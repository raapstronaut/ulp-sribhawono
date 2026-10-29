<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\ApkukGenerationRun;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\ApkukSyncLock;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\Work\WorkOrderCodeGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan WO PEMASANGAN_APKUK untuk POSKO: manual oleh Admin (Posko dipilih Admin) atau oleh
 * generator shift (Posko dari pemetaan Section terverifikasi).
 *
 * Duplikasi dicegah dua lapis: penilaian kandidat (WO_AKTIF) dan unique index
 * work_order_apkuk_targets.active_target_key untuk request yang bersamaan.
 *
 * WO manual menempati kuota enam per Posko per shift (ApkukShiftQuota): ditolak bila shift sudah
 * penuh, diperiksa di bawah kunci kuota yang sama dengan generator.
 */
class ApkukWorkOrderService
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly ApkukCandidateService $candidates,
        private readonly ShiftService $shifts,
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly ApkukShiftQuota $quota,
    ) {}

    /**
     * @param  array{apkuk_id: int|string, posko_id: string, work_date: string, shift_code: string, notes?: string|null}  $input
     */
    public function createManual(User $admin, array $input, ?CarbonImmutable $now = null): WorkOrder
    {
        $now ??= CarbonImmutable::now();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): WorkOrder => $this->create($admin, $input, $now));
            } catch (UniqueConstraintViolationException $e) {
                // Request lain lebih dulu membuat WO aktif untuk tiang yang sama.
                $candidate = $this->candidates->find((int) $input['apkuk_id']);
                if ($candidate?->activeWorkOrderCode !== null) {
                    throw $this->duplicateException($candidate->activeWorkOrderCode);
                }
                // Selain itu kemungkinan bentrok kode WO; ulangi dengan nomor berikutnya.
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array{apkuk_id: int|string, posko_id: string, work_date: string, shift_code: string, notes?: string|null}  $input
     */
    private function create(User $admin, array $input, CarbonImmutable $now): WorkOrder
    {
        // Kunci kuota shift tujuan lebih dulu (urutan sama dengan generator: kuota → ApkukSyncLock),
        // agar generator atau WO manual lain untuk shift yang sama tidak membaca slot bersamaan.
        ApkukShiftQuota::lock((string) $input['work_date'], (string) $input['shift_code']);
        // Menunggu apkuk:sync yang sedang menulis, agar laporan yang baru masuk antrean
        // rekonsiliasi tidak lolos sebagai target WO.
        ApkukSyncLock::shared();

        $candidate = $this->candidates->find((int) $input['apkuk_id']);
        if ($candidate === null) {
            throw ValidationException::withMessages(['apkuk_id' => 'Data APKUK tidak ditemukan.']);
        }
        if ($candidate->activeWorkOrderCode !== null) {
            throw $this->duplicateException($candidate->activeWorkOrderCode);
        }
        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages([
                'apkuk_id' => 'Lokasi tidak memenuhi syarat WO APKUK: '.implode('; ', $candidate->issueLabels()).'.',
            ]);
        }

        // POSKO wajib dipilih Admin; tidak diturunkan dari penyulang.
        $posko = Posko::query()->whereKey($input['posko_id'])->where('is_active', true)->first();
        if ($posko === null) {
            throw ValidationException::withMessages(['posko_id' => 'POSKO tidak ditemukan atau tidak aktif.']);
        }

        if (! in_array($input['shift_code'], $this->shifts->codes(), true)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift tidak dikenal.']);
        }
        $window = $this->shifts->window($input['work_date'], $input['shift_code']);
        if ($window['end']->lte($now)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift yang dipilih sudah berakhir.']);
        }

        // Dibaca di bawah kunci kuota: WO yang baru di-commit generator atau Admin lain ikut terhitung.
        $this->quota->assertSlotAvailable($posko, $input['work_date'], $input['shift_code']);

        return $this->store($candidate, $posko, $input['work_date'], $input['shift_code'], $window, $now, $admin, [
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : null,
        ]);
    }

    /**
     * WO SYSTEM dari generator shift (ApkukShiftGenerator) untuk kandidat yang sudah dinilai dan
     * dipetakan ke Posko di dalam transaksi generator (kunci generator, kunci kuota, dan ApkukSyncLock
     * sudah dipegang; slot sudah dihitung generator).
     * Savepoint per WO: target yang ternyata sudah punya WO aktif ditolak tanpa membatalkan WO lain.
     *
     * @param  array<string, mixed>  $provenance  strategi, peringkat, alasan, pemetaan Posko
     */
    public function createSystem(ApkukCandidate $candidate, Posko $posko, ShiftInstance $shift, ApkukGenerationRun $run, array $provenance, CarbonImmutable $now): WorkOrder
    {
        if ($candidate->activeWorkOrderCode !== null) {
            throw $this->duplicateException($candidate->activeWorkOrderCode);
        }
        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages(['apkuk_id' => 'Lokasi tidak memenuhi syarat WO APKUK: '.implode('; ', $candidate->issueLabels()).'.']);
        }
        $window = ['start' => CarbonImmutable::instance($shift->starts_at), 'end' => CarbonImmutable::instance($shift->ends_at)];
        if ($window['end']->lte($now)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift sudah berakhir.']);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): WorkOrder => $this->store($candidate, $posko, $shift->operational_date->toDateString(), $shift->shift_code, $window, $now, null, [
                    'source' => WorkOrder::SOURCE_SYSTEM,
                    'generation_run_id' => $run->getKey(),
                ], $provenance + ['generation_run_id' => $run->getKey()]));
            } catch (UniqueConstraintViolationException $e) {
                // Target sudah punya WO aktif (dibuat bersamaan), atau bentrok kode WO.
                $active = WorkOrderApkukTarget::query()->where('active_target_key', $candidate->targetKey)->with('workOrder:id,code')->first();
                if ($active !== null) {
                    throw $this->duplicateException((string) $active->workOrder?->code);
                }
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     * @param  array{source: string, notes?: ?string, generation_run_id?: int}  $attributes
     * @param  array<string, mixed>  $payload  konteks tambahan log CREATED
     */
    private function store(ApkukCandidate $candidate, Posko $posko, string $workDate, string $shiftCode, array $window, CarbonImmutable $now, ?User $actor, array $attributes, array $payload = []): WorkOrder
    {
        $apkuk = $candidate->apkuk;
        $appTimezone = config('app.timezone');

        $workOrder = WorkOrder::create($attributes + [
            'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_APKUK, $now),
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'status' => WorkOrderStatus::Ditugaskan,
            'posko_id' => $posko->getKey(),
            'penyulang_id' => $candidate->penyulang->getKey(),
            'section_name' => $candidate->sectionName,
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
            'scheduled_start_at' => $window['start']->setTimezone($appTimezone),
            'due_at' => $window['end']->setTimezone($appTimezone),
            'created_by_user_id' => $actor?->getKey(),
        ]);

        WorkOrderApkukTarget::create([
            'work_order_id' => $workOrder->getKey(),
            'apkuk_id' => $apkuk->id,
            'target_key' => $candidate->targetKey,
            'active_target_key' => $candidate->targetKey,
            'no_tiang' => $apkuk->no_tiang,
            'penyulang_source' => $apkuk->penyulang,
            'section_source' => $apkuk->section,
            'naik_dari' => $apkuk->naik_dari,
            'source_report_date' => $apkuk->tanggal,
            'source_status' => $apkuk->status,
            'source_terpasang_bh' => $apkuk->terpasang_bh,
            'source_kurang_bh' => $apkuk->kurang_bh,
            'latitude' => $apkuk->latitude,
            'longitude' => $apkuk->longitude,
            'target_quantity' => $candidate->targetQuantity(),
        ]);

        $this->lifecycle->log(
            $workOrder,
            WorkOrderStatusLog::ACTION_CREATED,
            null,
            WorkOrderStatus::Ditugaskan,
            $actor,
            null,
            [
                'source' => $attributes['source'],
                'apkuk_id' => $apkuk->id,
                'apkuk_source_id' => $apkuk->source_id,
                'target_key' => $candidate->targetKey,
                'target_quantity' => $candidate->targetQuantity(),
                'posko_id' => $posko->getKey(),
                'work_date' => $workDate,
                'shift_code' => $shiftCode,
                'section_name' => $candidate->sectionName,
                'section_origin' => $candidate->sectionOrigin,
                'warnings' => $candidate->warnings,
            ] + $payload,
        );

        return $workOrder;
    }

    private function duplicateException(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'apkuk_id' => "Tiang ini sudah memiliki WO APKUK aktif ({$code}). Selesaikan atau batalkan WO tersebut terlebih dahulu.",
        ]);
    }
}
