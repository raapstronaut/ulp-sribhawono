<?php

namespace App\Services\Pasila;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\InspectionFinding;
use App\Models\P0Item;
use App\Models\PasilaFinding;
use App\Models\PasilaSession;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Audit\AuditLogger;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\WorkflowConflictException;
use App\Services\Work\WorkOrderCodeGenerator;
use App\Services\Work\WorkSchedule;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Workflow P0 POSKO (Batch B3.3).
 *
 * - Setiap P0 terkait sesi PASILA dan sumber masalah yang tercatat: temuan PASILA milik Posko sesi
 *   (sesi itu atau sebelumnya) atau temuan inspeksi yang sudah divalidasi Admin. Section dan Penyulang
 *   diambil dari sumber dan harus terverifikasi milik Posko sesi pada referensi terkini.
 * - Usulan SYSTEM menunggu ACC Admin dan bukan pekerjaan. ACC membuat WO P0; tolak menimbulkan
 *   kewajiban P0 manual pengganti dalam sesi yang sama. P0 manual Admin divalidasi setara.
 * - WO P0 dikerjakan POSKO lewat alur WO yang ada (laporan BEFORE/AFTER, GPS, petugas, verifikasi
 *   Admin). Target P0 shift terpenuhi hanya oleh WO P0 yang SELESAI.
 * - Satu sumber hanya boleh punya satu P0 berjalan (active_source_key unik); titik yang sama tidak
 *   boleh punya dua WO P0 aktif (active_target_key). P0 tidak termasuk kuota WO APKUK.
 * - Tidak ada kandidat yang dikarang: tanpa sumber sah, tidak ada P0 dan kewajiban tetap tampil.
 */
class P0Service
{
    public const TARGET_PER_SHIFT = 1;

    private const MIN_TEXT = 10;

    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly WorkSchedule $schedule,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly LocationTargetResolver $targets,
        private readonly AuditLogger $audit,
        private readonly P0CandidateStrategy $strategy,
    ) {}

    public function strategy(): P0CandidateStrategy
    {
        return $this->strategy;
    }

    /**
     * Sumber P0 yang sah untuk sesi dan belum memiliki P0 berjalan.
     *
     * @return array<int, array{source_type: string, source_id: string, label: string}>
     */
    public function candidates(PasilaSession $session): array
    {
        $session->loadMissing('shiftInstance');
        $map = SectionPoskoMap::load();
        $busy = P0Item::query()->whereNotNull('active_source_key')->pluck('active_source_key')->flip();
        $owned = fn (?string $section) => ($entry = $map->entry($section)) !== null && $entry['posko']?->getKey() === $session->posko_id;
        $sources = [];

        $findings = PasilaFinding::query()
            ->where('posko_id', $session->posko_id)
            ->whereHas('session.shiftInstance', fn ($q) => $q->where('starts_at', '<=', $session->shiftInstance->starts_at))
            ->with('session:id,code')
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();
        foreach ($findings as $finding) {
            if (! isset($busy[P0Item::SOURCE_PASILA_FINDING.':'.$finding->id]) && $owned($finding->section_key)) {
                $sources[] = ['source_type' => P0Item::SOURCE_PASILA_FINDING, 'source_id' => $finding->id,
                    'label' => "Temuan PASILA {$finding->session->code} #{$finding->sequence} · {$finding->finding_code} · {$finding->section_name}"];
            }
        }

        $inspections = InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->whereNull('follow_up_work_order_id')
            ->whereHas('inspection', fn ($q) => $q->whereNotNull('section_name')->where('visited_at', '<', $session->shiftInstance->ends_at))
            ->with('inspection:id,code,section_name,penyulang_id')
            ->latest()
            ->limit(200)
            ->get();
        foreach ($inspections as $finding) {
            $entry = $map->entry($finding->inspection->section_name);
            if (! isset($busy[P0Item::SOURCE_INSPECTION_FINDING.':'.$finding->id]) && $entry !== null
                && $entry['posko']?->getKey() === $session->posko_id && $entry['penyulang_model']?->getKey() === $finding->inspection->penyulang_id) {
                $sources[] = ['source_type' => P0Item::SOURCE_INSPECTION_FINDING, 'source_id' => $finding->id,
                    'label' => "Temuan inspeksi {$finding->inspection->code} · {$finding->finding_code} · {$entry['section']}"];
            }
        }

        return $sources;
    }

    /**
     * Usulan SYSTEM dari strategi yang siap; strategi yang belum siap ditolak tanpa membuat apa pun.
     *
     * @return array{status: string, message: ?string, created: array<int, P0Item>}
     */
    public function runStrategy(PasilaSession $session): array
    {
        if ($reason = $this->strategy->notReadyReason()) {
            return ['status' => 'REFUSED', 'message' => $reason, 'created' => []];
        }

        $created = [];
        foreach ($this->strategy->propose($session, $this->candidates($session)) as $proposal) {
            $created[] = $this->proposeSystem($session, $proposal['source_type'], $proposal['source_id'], $proposal['description'], [
                'strategy' => $this->strategy->name(),
                'version' => $this->strategy->version(),
                'is_machine_learning' => $this->strategy->isMachineLearning(),
                'reasons' => $proposal['reasons'],
            ]);
        }

        return ['status' => 'COMPLETED', 'message' => null, 'created' => $created];
    }

    /**
     * Usulan P0 SYSTEM: MENUNGGU_ACC, belum menjadi pekerjaan.
     *
     * @param  array<string, mixed>  $recommendation
     */
    public function proposeSystem(PasilaSession $session, string $sourceType, string $sourceId, string $description, array $recommendation): P0Item
    {
        return $this->withRetry(fn () => DB::transaction(function () use ($session, $sourceType, $sourceId, $description, $recommendation): P0Item {
            $session = $this->lockSession($session);
            $source = $this->resolveSource($session, $sourceType, $sourceId);
            $item = $this->newItem($session, $source, P0Item::ORIGIN_SYSTEM, $this->text($description, 'description'), null, [
                'approval_status' => P0Item::APPROVAL_MENUNGGU,
                'recommendation' => $recommendation + ['is_machine_learning' => false],
            ]);
            $this->audit->record('p0_item', $item->getKey(), $item->code, AuditLog::ACTION_CREATED, null, $this->state($item), null);

            return $item;
        }));
    }

    public function approve(P0Item $item, User $admin, string $workDate, string $shiftCode, ?string $note = null): P0Item
    {
        return $this->withRetry(fn () => DB::transaction(function () use ($item, $admin, $workDate, $shiftCode, $note): P0Item {
            $item = $this->lockPending($item, P0Item::ORIGIN_SYSTEM);
            $before = $this->state($item);
            // Sumber dan kepemilikan Section diperiksa ulang: referensi bisa berubah sejak usulan dibuat.
            $source = $this->resolveSource($item->session, $item->source_type, (string) ($item->pasila_finding_id ?? $item->inspection_finding_id));
            $workOrder = $this->createWorkOrder($item, $source, $admin, WorkOrder::SOURCE_SYSTEM, $workDate, $shiftCode);

            $item->forceFill([
                'approval_status' => P0Item::APPROVAL_DISETUJUI,
                'work_order_id' => $workOrder->getKey(),
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => filled($note) ? trim((string) $note) : null,
            ])->save();
            $this->audit->record('p0_item', $item->getKey(), $item->code, AuditLog::ACTION_APPROVED, $before, $this->state($item), $admin, $item->review_note);

            return $item;
        }));
    }

    public function reject(P0Item $item, User $admin, string $note): P0Item
    {
        $note = $this->text($note, 'review_note');

        return DB::transaction(function () use ($item, $admin, $note): P0Item {
            $item = $this->lockPending($item, P0Item::ORIGIN_SYSTEM);
            $before = $this->state($item);
            $item->forceFill([
                'approval_status' => P0Item::APPROVAL_DITOLAK,
                'replacement_status' => P0Item::REPLACEMENT_WAJIB,
                'active_source_key' => null,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->record('p0_item', $item->getKey(), $item->code, AuditLog::ACTION_REJECTED, $before, $this->state($item), $admin, $note);

            return $item;
        });
    }

    /**
     * P0 manual Admin (langsung menjadi WO P0), opsional sebagai pengganti usulan SYSTEM yang ditolak
     * dalam sesi yang sama.
     *
     * @param  array{source_type: string, source_id: string, description: string, work_date: string, shift_code: string, replaces_p0_item_id?: ?string}  $input
     */
    public function createManual(PasilaSession $session, User $admin, array $input): P0Item
    {
        $description = $this->text($input['description'], 'description');

        return $this->withRetry(fn () => DB::transaction(function () use ($session, $admin, $input, $description): P0Item {
            $session = $this->lockSession($session);
            $replaced = null;
            if (filled($input['replaces_p0_item_id'] ?? null)) {
                $replaced = P0Item::query()->whereKey($input['replaces_p0_item_id'])->lockForUpdate()->first();
                if ($replaced === null || $replaced->pasila_session_id !== $session->getKey()
                    || $replaced->approval_status !== P0Item::APPROVAL_DITOLAK || $replaced->replacement_status !== P0Item::REPLACEMENT_WAJIB) {
                    throw ValidationException::withMessages(['replaces_p0_item_id' => 'P0 pengganti hanya untuk usulan SYSTEM yang ditolak dan belum diganti dalam sesi PASILA yang sama.']);
                }
            }

            $source = $this->resolveSource($session, $input['source_type'], $input['source_id']);
            $item = $this->newItem($session, $source, P0Item::ORIGIN_ADMIN, $description, $admin, [
                'approval_status' => P0Item::APPROVAL_TIDAK_PERLU,
                'replaces_p0_item_id' => $replaced?->getKey(),
            ]);
            $workOrder = $this->createWorkOrder($item, $source, $admin, WorkOrder::SOURCE_ADMIN_MANUAL, $input['work_date'], $input['shift_code']);
            $item->forceFill(['work_order_id' => $workOrder->getKey()])->save();
            $this->audit->record('p0_item', $item->getKey(), $item->code, AuditLog::ACTION_CREATED, null, $this->state($item), $admin, $replaced ? 'Pengganti '.$replaced->code : null);

            if ($replaced !== null) {
                $before = $this->state($replaced);
                $replaced->forceFill(['replacement_status' => P0Item::REPLACEMENT_TERPENUHI])->save();
                $this->audit->record('p0_item', $replaced->getKey(), $replaced->code, AuditLog::ACTION_UPDATED, $before, $this->state($replaced), $admin, 'Diganti P0 manual '.$item->code);
            }

            return $item;
        }));
    }

    /**
     * Dua kewajiban terpisah dalam satu sesi:
     * - target P0 shift: terpenuhi hanya oleh WO P0 berstatus SELESAI (pengganti yang baru dibuat belum memenuhi);
     * - kewajiban Admin menyediakan pengganti: jumlah usulan ditolak yang belum diganti.
     *
     * @param  Collection<int, P0Item>  $items  P0 sesi (dengan workOrder)
     * @return array{target: int, completed: int, fulfilled: bool, replacement_open: int, stages: array<string, int>}
     */
    public static function obligation(Collection $items): array
    {
        $stages = $items->countBy(fn (P0Item $item) => $item->stage())->all();
        $completed = $stages[P0Item::STAGE_SELESAI] ?? 0;

        return [
            'target' => self::TARGET_PER_SHIFT,
            'completed' => $completed,
            'fulfilled' => $completed >= self::TARGET_PER_SHIFT,
            'replacement_open' => $stages[P0Item::STAGE_MENUNGGU_PENGGANTI] ?? 0,
            'stages' => $stages,
        ];
    }

    /**
     * @return array{type: string, finding: PasilaFinding|InspectionFinding, entry: array<string, mixed>, target: array<string, mixed>, snapshot: array<string, mixed>}
     */
    private function resolveSource(PasilaSession $session, string $type, string $id): array
    {
        $session->loadMissing(['shiftInstance', 'posko']);
        $map = SectionPoskoMap::load();
        $owned = function (?array $entry, string $what) use ($session): array {
            if ($entry === null || $entry['posko'] === null) {
                throw ValidationException::withMessages(['source' => "Section {$what} tidak terverifikasi pada referensi SECTION_PIC_RAW terkini."]);
            }
            if ($entry['posko']->getKey() !== $session->posko_id) {
                throw ValidationException::withMessages(['source' => "Section {$entry['section']} ({$entry['penyulang']}) milik Posko {$entry['posko']->nama_posko}, bukan Posko {$session->posko->nama_posko}."]);
            }

            return $entry;
        };

        if ($type === P0Item::SOURCE_PASILA_FINDING) {
            $finding = PasilaFinding::query()->with('session.shiftInstance')->find($id)
                ?? throw ValidationException::withMessages(['source' => 'Temuan PASILA sumber tidak ditemukan.']);
            if ($finding->posko_id !== $session->posko_id) {
                throw ValidationException::withMessages(['source' => 'Temuan PASILA milik Posko lain tidak dapat menjadi sumber P0 Posko ini.']);
            }
            if ($finding->session->shiftInstance->starts_at->gt($session->shiftInstance->starts_at)) {
                throw ValidationException::withMessages(['source' => 'Temuan berasal dari sesi yang lebih baru daripada sesi PASILA P0 ini.']);
            }
            $entry = $owned($map->entry($finding->section_key), $finding->section_name);

            return [
                'type' => $type,
                'finding' => $finding,
                'entry' => $entry,
                'target' => [
                    'target_type' => WorkOrderLocationTarget::TYPE_PASILA,
                    'source_ref' => (string) $finding->getKey(),
                    'target_key' => 'PASILA:'.$finding->getKey(),
                    'label' => $finding->asset_label ?: "Temuan PASILA {$finding->session->code} #{$finding->sequence}",
                    'asset_type' => null,
                    'penyulang_source' => $entry['penyulang'],
                    'section_source' => $finding->section_name,
                    'latitude' => $finding->latitude,
                    'longitude' => $finding->longitude,
                    'snapshot' => ['pasila_finding_id' => $finding->getKey(), 'finding_code' => $finding->finding_code],
                ],
                'snapshot' => [
                    'session_code' => $finding->session->code,
                    'sequence' => $finding->sequence,
                    'finding_code' => $finding->finding_code,
                    'description' => $finding->description,
                    'section' => $finding->section_name,
                    'latitude' => (string) $finding->latitude,
                    'longitude' => (string) $finding->longitude,
                    'occurred_at' => $finding->occurred_at->toIso8601String(),
                ],
            ];
        }

        if ($type === P0Item::SOURCE_INSPECTION_FINDING) {
            $finding = InspectionFinding::query()->with('inspection.penyulang')->find($id)
                ?? throw ValidationException::withMessages(['source' => 'Temuan inspeksi sumber tidak ditemukan.']);
            if ($finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI) {
                throw ValidationException::withMessages(['source' => 'Temuan inspeksi belum divalidasi (ACC) Admin, sehingga belum terbukti.']);
            }
            if ($finding->follow_up_work_order_id !== null) {
                throw ValidationException::withMessages(['source' => 'Temuan inspeksi ini sudah memiliki WO tindak lanjut.']);
            }
            $inspection = $finding->inspection;
            if (! filled($inspection->section_name)) {
                throw ValidationException::withMessages(['source' => 'Kunjungan inspeksi tidak mencatat Section, sehingga kepemilikan Posko tidak dapat dibuktikan.']);
            }
            if ($inspection->visited_at->gte($session->shiftInstance->ends_at)) {
                throw ValidationException::withMessages(['source' => 'Temuan inspeksi tercatat setelah shift sesi PASILA ini.']);
            }
            $entry = $owned($map->entry($inspection->section_name), $inspection->section_name);
            if ($entry['penyulang_model']->getKey() !== $inspection->penyulang_id) {
                throw ValidationException::withMessages(['source' => "Section {$entry['section']} tercatat pada penyulang lain dari kunjungan inspeksi."]);
            }

            return [
                'type' => $type,
                'finding' => $finding,
                'entry' => $entry,
                'target' => $this->targets->fromFinding($finding, $inspection->penyulang),
                'snapshot' => [
                    'inspection_code' => $inspection->code,
                    'finding_code' => $finding->finding_code,
                    'description' => $finding->description,
                    'section' => $entry['section'],
                    'latitude' => $finding->latitude === null ? null : (string) $finding->latitude,
                    'longitude' => $finding->longitude === null ? null : (string) $finding->longitude,
                    'occurred_at' => $inspection->visited_at->toIso8601String(),
                    'validated_at' => $finding->validated_at?->toIso8601String(),
                ],
            ];
        }

        throw ValidationException::withMessages(['source' => 'Jenis sumber P0 tidak dikenal.']);
    }

    /**
     * @param  array{type: string, finding: PasilaFinding|InspectionFinding, entry: array<string, mixed>, snapshot: array<string, mixed>}  $source
     * @param  array<string, mixed>  $attributes
     */
    private function newItem(PasilaSession $session, array $source, string $origin, string $description, ?User $admin, array $attributes): P0Item
    {
        $sequence = (int) P0Item::query()->where('pasila_session_id', $session->getKey())->max('sequence') + 1;

        return P0Item::create($attributes + [
            'code' => 'P0-'.preg_replace('/^PSL-/', '', $session->code).'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'pasila_session_id' => $session->getKey(),
            'sequence' => $sequence,
            'posko_id' => $session->posko_id,
            'penyulang_id' => $source['entry']['penyulang_model']->getKey(),
            'section_key' => $source['entry']['key'],
            'section_name' => $source['entry']['section'],
            'origin' => $origin,
            'source_type' => $source['type'],
            'pasila_finding_id' => $source['type'] === P0Item::SOURCE_PASILA_FINDING ? $source['finding']->getKey() : null,
            'inspection_finding_id' => $source['type'] === P0Item::SOURCE_INSPECTION_FINDING ? $source['finding']->getKey() : null,
            'active_source_key' => $source['type'].':'.$source['finding']->getKey(),
            'source_snapshot' => $source['snapshot'],
            'description' => $description,
            'replacement_status' => P0Item::REPLACEMENT_TIDAK_PERLU,
            'created_by_user_id' => $admin?->getKey(),
        ]);
    }

    /**
     * @param  array{target: array<string, mixed>}  $source
     */
    private function createWorkOrder(P0Item $item, array $source, User $admin, string $woSource, string $workDate, string $shiftCode): WorkOrder
    {
        $now = CarbonImmutable::now();
        $schedule = $this->schedule->forShift($workDate, $shiftCode, $now);
        $activeKey = implode('|', [WorkOrder::TEAM_POSKO, WorkOrder::TYPE_P0, $source['target']['target_key']]);
        if ($existing = WorkOrderLocationTarget::query()->where('active_target_key', $activeKey)->with('workOrder:id,code')->first()) {
            throw ValidationException::withMessages(['source' => "Titik sumber ini sudah memiliki WO P0 aktif ({$existing->workOrder?->code})."]);
        }

        $workOrder = WorkOrder::create([
            'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_P0, $now),
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_P0,
            'source' => $woSource,
            'status' => WorkOrderStatus::Ditugaskan,
            'posko_id' => $item->posko_id,
            'penyulang_id' => $item->penyulang_id,
            'section_name' => $item->section_name,
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
            'scheduled_start_at' => $schedule['scheduled_start_at'],
            'due_at' => $schedule['due_at'],
            'notes' => $item->description,
            'created_by_user_id' => $admin->getKey(),
        ]);
        WorkOrderLocationTarget::create($source['target'] + ['work_order_id' => $workOrder->getKey(), 'active_target_key' => $activeKey]);
        $this->lifecycle->log($workOrder, WorkOrderStatusLog::ACTION_CREATED, null, WorkOrderStatus::Ditugaskan, $admin, null, [
            'p0_item_id' => $item->getKey(),
            'p0_code' => $item->code,
            'p0_origin' => $item->origin,
            'pasila_session_id' => $item->pasila_session_id,
            'source_type' => $item->source_type,
            'target_ref' => $source['target']['source_ref'],
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
        ]);

        return $workOrder;
    }

    private function lockSession(PasilaSession $session): PasilaSession
    {
        return PasilaSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockPending(P0Item $item, string $origin): P0Item
    {
        $locked = P0Item::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->origin !== $origin || $locked->approval_status !== P0Item::APPROVAL_MENUNGGU) {
            throw new WorkflowConflictException("P0 {$locked->code} sudah diproses ({$locked->approval_status}); tidak ada perubahan.");
        }

        return $locked;
    }

    /**
     * Kode WO dibangkitkan max+1 per tanggal; request bersamaan dapat bentrok kode dan diulang. Bentrok
     * sumber/titik yang sama dilaporkan sebagai galat validasi, bukan diulang.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRetry(callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'active_source_key')) {
                    throw ValidationException::withMessages(['source' => 'Sumber ini sudah memiliki P0 yang menunggu ACC atau berjalan.']);
                }
                if (str_contains($message, 'replaces_p0_item_id')) {
                    throw ValidationException::withMessages(['replaces_p0_item_id' => 'Usulan yang ditolak ini sudah memiliki P0 pengganti.']);
                }
                if (str_contains($message, 'active_target_key') || str_contains($message, 'work_order_id') || $attempt >= self::CODE_ATTEMPTS) {
                    throw ValidationException::withMessages(['source' => 'P0 untuk sumber ini baru saja dibuat proses lain; tidak ada P0 ganda.']);
                }
            }
        }
    }

    private function text(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) < self::MIN_TEXT) {
            throw ValidationException::withMessages([$field => 'Wajib diisi minimal '.self::MIN_TEXT.' karakter.']);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(P0Item $item): array
    {
        return $item->only(['pasila_session_id', 'origin', 'source_type', 'section_name', 'approval_status', 'replacement_status', 'work_order_id', 'replaces_p0_item_id']);
    }
}
