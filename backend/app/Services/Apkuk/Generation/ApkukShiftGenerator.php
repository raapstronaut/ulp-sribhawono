<?php

namespace App\Services\Apkuk\Generation;

use App\Models\Apkuk;
use App\Models\ApkukGenerationRun;
use App\Models\ApkukShiftAllocation;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\User;
use App\Services\Apkuk\ApkukSyncLock;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\ApkukCandidate;
use App\Services\ApkukCandidateService;
use App\Services\ApkukWorkOrderService;
use App\Services\ShiftService;
use App\Support\PenyulangName;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Generator enam WO PEMASANGAN_APKUK per Posko per shift (Batch B2).
 *
 * Alur: identitas shift → kandidat sah (ApkukCandidateService: snapshot aktif, bukan pending/
 * histori, tanpa WO aktif) → Posko dari pemetaan Section terverifikasi (SectionPoskoMap; gagal →
 * ditahan dengan alasan) → kuota (limpahan sah + WO terjadwal lain) → urutan CandidateRanking →
 * WO SYSTEM lewat ApkukWorkOrderService.
 *
 * Mode PREVIEW tidak menulis WO maupun alokasi (hanya log eksekusi). Mode GENERATE:
 * - diblokir ApkukGeneratorGuard (fail-closed: hanya local/testing dengan aktivasi eksplisit);
 * - satu transaksi: kunci generator (advisory PostgreSQL) → kunci kuota shift (ApkukShiftQuota::lock,
 *   juga dipegang WO manual Admin) → ApkukSyncLock::shared; kandidat dan slot dinilai ulang di dalam
 *   kunci, baris alokasi unik per shift + Posko diklaim sebelum WO dibuat. Eksekusi
 *   ulang/terlambat/paralel tidak menambah WO di atas kuota; Posko yang sudah digenerate dilewati.
 *
 * Alokasi dibekukan sekali per shift + Posko. Kekurangan kandidat tidak diisi ulang otomatis
 * walau kandidat baru muncul kemudian; pratinjau membaca alokasi yang sama sehingga tidak
 * menjanjikan WO yang akan ditolak generate.
 */
final class ApkukShiftGenerator
{
    /** Kunci advisory generator (berbeda dari ApkukSyncLock::KEY). */
    public const LOCK_KEY = 7_202_610_290_002;

    public const POSKO_PREVIEW = 'PRATINJAU';

    public const POSKO_GENERATED = 'DIGENERATE';

    public const POSKO_ALREADY_GENERATED = 'SUDAH_DIGENERATE';

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly ApkukCandidateService $candidates,
        private readonly ApkukShiftQuota $quota,
        private readonly CandidateRanking $ranking,
        private readonly ApkukGeneratorGuard $guard,
        private readonly ApkukWorkOrderService $workOrders,
    ) {}

    public function ranking(): CandidateRanking
    {
        return $this->ranking;
    }

    public function blockedReason(): ?string
    {
        return $this->guard->blockedReason();
    }

    public function run(string $operationalDate, string $shiftCode, string $mode, string $trigger, ?User $actor = null, ?CarbonImmutable $now = null): ApkukGenerationRun
    {
        $now ??= CarbonImmutable::now();
        if (! in_array($shiftCode, $this->shifts->codes(), true)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift tidak dikenal.']);
        }
        if (! in_array($mode, [ApkukGenerationRun::MODE_PREVIEW, ApkukGenerationRun::MODE_GENERATE], true)) {
            throw ValidationException::withMessages(['mode' => 'Mode generator tidak dikenal.']);
        }

        $shift = ShiftInstance::ensure($this->shifts, $operationalDate, $shiftCode);
        $run = ApkukGenerationRun::create([
            'shift_instance_id' => $shift->getKey(),
            'mode' => $mode,
            'status' => ApkukGenerationRun::STATUS_FAILED,
            'trigger' => $trigger,
            'strategy' => $this->ranking->name(),
            'strategy_version' => $this->ranking->version(),
            'is_machine_learning' => $this->ranking->isMachineLearning(),
            'app_environment' => (string) app()->environment(),
            'triggered_by_user_id' => $actor?->getKey(),
            'started_at' => $now,
        ]);

        if ($mode === ApkukGenerationRun::MODE_GENERATE) {
            $blocked = $this->blockedReason() ?? ($shift->ends_at->lte($now) ? 'Shift sudah berakhir; WO tidak dibuat untuk shift lampau.' : null);
            if ($blocked !== null) {
                return $this->finish($run, ApkukGenerationRun::STATUS_REFUSED, [], [], null, $blocked);
            }
        }

        try {
            [$summary, $held, $breakdown] = $mode === ApkukGenerationRun::MODE_PREVIEW
                ? $this->evaluate($shift, $run, $now, false)
                : DB::transaction(fn () => $this->evaluate($shift, $run, $now, true));

            return $this->finish($run, ApkukGenerationRun::STATUS_COMPLETED, $summary, $held, $breakdown, null);
        } catch (UniqueConstraintViolationException) {
            // Eksekusi lain mengklaim alokasi shift + Posko yang sama lebih dulu; transaksi ini dibatalkan utuh.
            return $this->finish($run, ApkukGenerationRun::STATUS_REFUSED, [], [], null, 'Alokasi shift ini baru saja dibuat eksekusi lain; tidak ada WO ganda yang dibuat.');
        } catch (Throwable $e) {
            report($e);

            return $this->finish($run, ApkukGenerationRun::STATUS_FAILED, [], [], null, mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>}
     */
    private function evaluate(ShiftInstance $shift, ApkukGenerationRun $run, CarbonImmutable $now, bool $write): array
    {
        $date = $shift->operational_date->toDateString();
        if ($write) {
            self::lock();
            // WO manual/pindah jadwal Admin untuk shift ini menunggu sampai generator commit, dan
            // sebaliknya; slot di bawah dibaca setelah penulis lain shift ini selesai.
            ApkukShiftQuota::lock($date, $shift->shift_code);
            // Menunggu apkuk:sync yang sedang menulis; antrean rekonsiliasi dibaca setelah commit.
            ApkukSyncLock::shared();
        }

        $candidates = $this->candidates->evaluate();
        [$byPosko, $held] = $this->assign($candidates, SectionPoskoMap::load());
        $scheduled = $this->quota->scheduled($date, $shift->shift_code);
        // Dibaca juga saat pratinjau agar rencana sama dengan yang akan diterima generate.
        $allocated = ApkukShiftAllocation::query()->where('shift_instance_id', $shift->getKey())->get()->keyBy('posko_id');

        $summary = [];
        $mappedByPosko = [];
        foreach (Posko::query()->where('is_active', true)->orderBy('nama_posko')->get() as $posko) {
            $carry = $scheduled[$posko->id]['carry_over'] ?? collect();
            $existing = $scheduled[$posko->id]['existing'] ?? collect();
            $ranked = $this->ranking->rank(collect($byPosko[$posko->id] ?? [])->pluck('candidate'), $shift);
            $mappedByPosko[$posko->nama_posko] = count($ranked);
            $base = [
                'posko_id' => $posko->id,
                'posko' => $posko->nama_posko,
                'carry_over_codes' => $carry->pluck('code')->all(),
                'existing_codes' => $existing->pluck('code')->all(),
                'eligible_candidates' => count($ranked),
            ];

            if ($allocation = $allocated->get($posko->id)) {
                // Angka beku dari alokasi; kekurangannya tidak diisi ulang walau kandidat baru tersedia.
                $summary[] = $base + ApkukShiftQuota::compute($allocation->carry_over_count, $allocation->existing_count, $allocation->generated_count) + [
                    'status' => self::POSKO_ALREADY_GENERATED,
                    'generated_by_run_id' => $allocation->generation_run_id,
                    // WO nyata saat ini (dapat berbeda dari angka beku: WO manual, limpahan, pembatalan).
                    'current_total' => $carry->count() + $existing->count(),
                    'new_this_run' => 0,
                    'planned' => [],
                    'created' => [],
                    'skipped' => [],
                ];

                continue;
            }

            $slots = ApkukShiftQuota::newCount($carry->count(), $existing->count(), PHP_INT_MAX);
            if (! $write) {
                $planned = array_slice($ranked, 0, $slots);
                $summary[] = $base + ApkukShiftQuota::compute($carry->count(), $existing->count(), count($planned)) + [
                    'status' => self::POSKO_PREVIEW,
                    'new_this_run' => count($planned),
                    'planned' => array_map(fn (RankedCandidate $item) => $item->toArray(), $planned),
                    'created' => [],
                    'skipped' => [],
                ];

                continue;
            }

            $summary[] = $base + $this->generateFor($shift, $run, $posko, $carry->count(), $existing->count(), $slots, $ranked, $byPosko[$posko->id] ?? [], $now);
        }

        $archived = Apkuk::query()->whereNotNull('source_missing_at')->count();

        return [$summary, $held, CandidateBreakdown::build($candidates, $mappedByPosko, $held, $archived)];
    }

    /**
     * @param  array<int, RankedCandidate>  $ranked
     * @param  array<int, array{candidate: ApkukCandidate, section: ?string}>  $mapped
     * @return array<string, mixed>
     */
    private function generateFor(ShiftInstance $shift, ApkukGenerationRun $run, Posko $posko, int $carry, int $existing, int $slots, array $ranked, array $mapped, CarbonImmutable $now): array
    {
        // Klaim alokasi lebih dulu: generator lain (atau eksekusi ulang) pada shift + Posko yang sama gagal di sini.
        $allocation = ApkukShiftAllocation::create([
            'shift_instance_id' => $shift->getKey(),
            'posko_id' => $posko->getKey(),
            'generation_run_id' => $run->getKey(),
            'target_count' => ApkukShiftQuota::TARGET_PER_SHIFT,
            'carry_over_count' => $carry,
            'existing_count' => $existing,
            'generated_count' => 0,
            'total_count' => $carry + $existing,
            'shortfall_count' => 0,
            'overload_count' => 0,
            'generated_at' => $now,
        ]);

        $sections = collect($mapped)->mapWithKeys(fn (array $item) => [$item['candidate']->apkuk->id => $item['section']]);
        $created = [];
        $skipped = [];
        foreach ($ranked as $item) {
            if (count($created) >= $slots) {
                break;
            }
            try {
                $workOrder = $this->workOrders->createSystem($item->candidate, $posko, $shift, $run, [
                    'strategy' => $this->ranking->name(),
                    'strategy_version' => $this->ranking->version(),
                    'is_machine_learning' => $this->ranking->isMachineLearning(),
                    'rank' => $item->rank,
                    'score' => $item->score,
                    'reasons' => $item->reasons,
                    'posko_mapping' => ['dasar' => 'SECTION_PIC_RAW', 'section' => $sections[$item->candidate->apkuk->id] ?? null],
                ], $now);
                $created[] = ['code' => $workOrder->code] + $item->toArray();
            } catch (ValidationException $e) {
                $skipped[] = ['reason' => collect($e->errors())->flatten()->first()] + $item->toArray();
            }
        }

        $numbers = ApkukShiftQuota::compute($carry, $existing, count($created));
        $allocation->update([
            'generated_count' => $numbers['new'],
            'total_count' => $numbers['total'],
            'shortfall_count' => $numbers['shortfall'],
            'overload_count' => $numbers['overload'],
        ]);

        return $numbers + [
            'status' => self::POSKO_GENERATED,
            'new_this_run' => count($created),
            'planned' => [],
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Kandidat sah per Posko PIC; yang tidak dapat dipetakan ditahan dengan alasan.
     *
     * @param  Collection<int, ApkukCandidate>  $candidates
     * @return array{0: array<string, array<int, array{candidate: ApkukCandidate, section: ?string}>>, 1: array<int, array<string, mixed>>}
     */
    private function assign(Collection $candidates, SectionPoskoMap $map): array
    {
        $byPosko = [];
        $held = [];
        foreach ($candidates->filter(fn (ApkukCandidate $candidate) => $candidate->isEligible()) as $candidate) {
            // Section efektif (sumber atau koreksi Admin B1); bila kosong/DUMMY nilai sumber dipakai untuk alasan.
            $section = $candidate->sectionName ?? $candidate->apkuk->section;
            $resolved = $map->resolve(PenyulangName::canonical($candidate->penyulang?->kode_penyulang), $section);
            if ($resolved['posko'] === null) {
                $held[] = [
                    'apkuk_id' => $candidate->apkuk->id,
                    'source_id' => $candidate->apkuk->source_id,
                    'no_tiang' => $candidate->apkuk->no_tiang,
                    'penyulang' => $candidate->penyulang?->nama_penyulang,
                    'section_sumber' => $candidate->apkuk->section,
                    'section_efektif' => $candidate->sectionName,
                    'section_origin' => $candidate->sectionOrigin,
                    'reason' => $resolved['reason'],
                    'reason_label' => SectionPoskoMap::LABELS[$resolved['reason']] ?? $resolved['reason'],
                ];

                continue;
            }
            $byPosko[$resolved['posko']->id][] = ['candidate' => $candidate, 'section' => $resolved['section']];
        }

        return [$byPosko, $held];
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @param  array<int, array<string, mixed>>  $held
     * @param  array<string, mixed>|null  $breakdown
     */
    private function finish(ApkukGenerationRun $run, string $status, array $summary, array $held, ?array $breakdown, ?string $message): ApkukGenerationRun
    {
        $run->forceFill([
            'status' => $status,
            'summary' => $summary,
            'held' => $held,
            'breakdown' => $breakdown,
            'message' => $message,
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    private static function lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '".ApkukSyncLock::LOCK_TIMEOUT."'");
        DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }
}
