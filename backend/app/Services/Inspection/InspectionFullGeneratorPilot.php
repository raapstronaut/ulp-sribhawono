<?php

namespace App\Services\Inspection;

use App\Enums\WorkOrderStatus;
use App\Models\ApkukGenerationRun;
use App\Models\ApkukShiftAllocation;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\ApkukCandidateService;
use App\Support\PenyulangName;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderCodeGenerator;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * V6A pilot ONLY. No CLI, route or scheduler entry point.
 * The write method is irrevocably restricted to testing + SQLite :memory:.
 * Production integration requires a separate audited V6B patch.
 */
final class InspectionFullGeneratorPilot
{
    public const TARGET = 6;
    // Legacy strategy ID retained so previous allocations remain recognizable.
    public const STRATEGY = 'INSPEKSI_FULL_BASELINE';
    public const VERSION = 'v6a.1';

    public function __construct(
        private readonly InspectionFullCandidatePool $pool,
        private readonly ShiftService $shifts,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkOrderLifecycleService $lifecycle,
    ) {}

    /** Read-only; no ShiftInstance, GenerationRun or WO is created. */
    public function preview(string $date, string $shift): array
    {
        $this->validateShift($date, $shift);
        $pool = $this->pool->evaluate();
        $rows = [];
        $poskos = Posko::query()->where('is_active', true)->orderBy('nama_posko')->get();
        // A legacy allocation with zero WOs still owns the unique shift+POSKO row.
        // Read it without creating any shift instance or writing any records.
        $instance = ShiftInstance::query()->whereDate('operational_date', $date)
            ->where('shift_code', $shift)->first();
        $allocations = $instance === null ? collect() : ApkukShiftAllocation::query()
            ->where('shift_instance_id', $instance->getKey())->with('generationRun')->get()->keyBy('posko_id');

        // The writer rejects mixing sources for the entire shift, not just one Posko.
        // Include inactive Posko and missing/unverified target provenance in this check.
        $blockedByLegacy = $allocations->contains(fn ($allocation) =>
            $allocation->generationRun?->strategy !== self::STRATEGY)
            || $this->generatedForOriginalShift($date, $shift)
                ->whereDoesntHave('apkukTarget', fn ($q) => $q->whereNotNull('inspection_finding_id'))
                ->exists();
        foreach ($poskos as $posko) {
            // All SYSTEM WOs count toward the six, even if cancelled; no hidden re-generation.
            $system = $this->generatedForOriginalShift($date, $shift, $posko->getKey())
                ->with('apkukTarget')->get();
            $legacySystem = $system->contains(fn (WorkOrder $wo) => $wo->apkukTarget?->inspection_finding_id === null);
            $manual = $this->workOrderQuery($date, $shift, $posko->getKey())
                ->where('status', '!=', WorkOrderStatus::Dibatalkan->value)
                ->whereDoesntHave('generationRun.shiftInstance', fn ($q) => $q
                    ->whereDate('operational_date', $date)->where('shift_code', $shift))
                ->count();
            $existingSystem = $system->count();
            $allocation = $allocations->get($posko->getKey());
            $legacyAllocationConflict = $allocation !== null
                && $allocation->generationRun?->strategy !== self::STRATEGY;
            $slots = max(0, self::TARGET - $existingSystem);
            $ranked = $pool['by_posko'][$posko->getKey()] ?? [];
            // No misleading plans anywhere in a shift blocked by legacy generation.
            $planned = $blockedByLegacy ? [] : array_slice($ranked, 0, $slots);
            $rows[] = [
                'posko_id' => $posko->getKey(),
                'posko' => $posko->nama_posko,
                'system_existing' => $existingSystem,
                'manual_other' => $manual,
                'eligible' => count($ranked),
                'planned' => $planned,
                'new' => count($planned),
                'system_after' => $existingSystem + count($planned),
                'shortfall' => max(0, self::TARGET - $existingSystem - count($planned)),
                'legacy_system_conflict' => $legacySystem,
                'legacy_allocation_conflict' => $legacyAllocationConflict,
            ];
        }
        return ['date' => $date, 'shift' => $shift, 'rows' => $rows, 'held' => $pool['held'],
            'source_total' => $pool['total'], 'eligible' => $pool['eligible'], 'strategy' => self::STRATEGY,
            'blocked_by_legacy' => $blockedByLegacy];
    }

    /**
     * Test-harness-only generation: emits real in-memory WO rows for integration tests.
     * Never invoke this with PostgreSQL (local OR production) or file-backed SQLite.
     */
    public function generateForIsolatedTest(string $date, string $shift): ApkukGenerationRun
    {
        $this->assertIsolatedTestDatabase();
        $this->validateShift($date, $shift);
        $now = CarbonImmutable::now();
        if ($this->shifts->window($date, $shift)['end']->lte($now)) {
            throw new LogicException('Shift pilot sudah berakhir.');
        }

        return DB::transaction(function () use ($date, $shift, $now): ApkukGenerationRun {
            // Entire pilot run is atomic. SQLite :memory: executes these tests sequentially.
            ApkukShiftQuota::lock($date, $shift);
            $preview = $this->preview($date, $shift);
            if ($preview['blocked_by_legacy']) {
                throw new LogicException('Terdapat WO atau alokasi generator SPS legacy dalam shift; pilot menolak pencampuran sumber.');
            }
            $map = SectionPoskoMap::load();
            $instance = ShiftInstance::ensure($this->shifts, $date, $shift);
            $run = ApkukGenerationRun::create([
                'shift_instance_id' => $instance->getKey(),
                'mode' => ApkukGenerationRun::MODE_GENERATE,
                'status' => ApkukGenerationRun::STATUS_FAILED,
                'trigger' => ApkukGenerationRun::TRIGGER_CLI,
                'strategy' => self::STRATEGY,
                'strategy_version' => self::VERSION,
                'is_machine_learning' => false,
                'app_environment' => (string) app()->environment(),
                'started_at' => $now,
            ]);

            $summary = [];
            foreach ($preview['rows'] as $row) {
                $created = [];
                foreach ($row['planned'] as $rankIndex => $candidate) {
                    // Recheck under a row lock immediately before writing the WO.
                    $finding = InspectionFinding::query()->lockForUpdate()->findOrFail($candidate['finding_id']);
                    $inspection = $finding->inspection()->with('penyulang')->firstOrFail();
                    $key = ApkukCandidateService::targetKey($inspection->penyulang->kode_penyulang,
                        ApkukCandidateService::normalizePole($finding->apkuk_no_tiang));
                    $currentMapping = $map->resolve(
                        PenyulangName::canonical($inspection->penyulang->kode_penyulang),
                        $inspection->section_name,
                    );
                    if (! $inspection->penyulang->is_active
                        || $currentMapping['posko']?->getKey() !== $candidate['posko']->getKey()
                        || $currentMapping['section'] !== $candidate['section']
                        || $finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
                        || $finding->suggested_team_type !== WorkOrder::TEAM_POSKO
                        || $finding->follow_up_work_order_id !== null
                        || $key !== $candidate['target_key']
                        || (int) $finding->apkuk_target_quantity !== $candidate['quantity']
                        || WorkOrderApkukTarget::query()->where('active_target_key', $key)->exists()) {
                        throw new LogicException('Pool berubah saat generate; seluruh transaksi pilot dibatalkan.');
                    }
                    $posko = $candidate['posko'];
                    $window = $this->shifts->window($date, $shift);
                    $wo = WorkOrder::create([
                        'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_APKUK, $now),
                        'team_type' => WorkOrder::TEAM_POSKO,
                        'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
                        'source' => WorkOrder::SOURCE_SYSTEM,
                        'status' => WorkOrderStatus::Ditugaskan,
                        'posko_id' => $posko->getKey(),
                        'penyulang_id' => $inspection->penyulang->getKey(),
                        'section_name' => $candidate['section'],
                        'work_date' => $date,
                        'shift_code' => $shift,
                        'scheduled_start_at' => $window['start']->setTimezone(config('app.timezone')),
                        'due_at' => $window['end']->setTimezone(config('app.timezone')),
                        'notes' => $finding->description,
                        'generation_run_id' => $run->getKey(),
                    ]);
                    WorkOrderApkukTarget::create([
                        'work_order_id' => $wo->getKey(),
                        'apkuk_id' => null,
                        'inspection_finding_id' => $finding->getKey(),
                        'target_key' => $key,
                        'active_target_key' => $key,
                        'no_tiang' => ApkukCandidateService::normalizePole($finding->apkuk_no_tiang),
                        'penyulang_source' => $inspection->penyulang->kode_penyulang,
                        'section_source' => $inspection->section_name,
                        'source_report_date' => $inspection->visited_at?->setTimezone(ShiftService::TIMEZONE)->toDateString(),
                        'source_status' => 'INSPEKSI_DISETUJUI',
                        'source_kurang_bh' => (int) $finding->apkuk_target_quantity,
                        'latitude' => $finding->latitude,
                        'longitude' => $finding->longitude,
                        'target_quantity' => (int) $finding->apkuk_target_quantity,
                    ]);
                    $finding->forceFill(['follow_up_work_order_id' => $wo->getKey()])->save();
                    $this->lifecycle->log($wo, WorkOrderStatusLog::ACTION_CREATED, null,
                        WorkOrderStatus::Ditugaskan, null, null, [
                            'source' => WorkOrder::SOURCE_SYSTEM,
                            'origin' => 'INSPEKSI',
                            'inspection_id' => $inspection->getKey(),
                            'inspection_finding_id' => $finding->getKey(),
                            'apkuk_id' => null,
                            'target_key' => $key,
                            'target_quantity' => (int) $finding->apkuk_target_quantity,
                            'posko_id' => $posko->getKey(),
                            'work_date' => $date,
                            'shift_code' => $shift,
                            'strategy' => self::STRATEGY,
                            'strategy_version' => self::VERSION,
                            'is_machine_learning' => false,
                            'rank' => $rankIndex + 1,
                            'generation_run_id' => $run->getKey(),
                            'posko_mapping' => ['dasar' => 'SECTION_PIC_RAW', 'section' => $candidate['section']],
                        ]);
                    $created[] = $wo->code;
                }
                $sysAfter = $this->generatedForOriginalShift($date, $shift, $row['posko_id'])->count();
                $alloc = ApkukShiftAllocation::query()
                    ->where('shift_instance_id', $instance->getKey())
                    ->where('posko_id', $row['posko_id'])->first();
                $values = [
                    'target_count' => self::TARGET,
                    'carry_over_count' => 0,
                    'existing_count' => $row['manual_other'],
                    'generated_count' => $sysAfter,
                    'total_count' => $row['manual_other'] + $sysAfter,
                    'shortfall_count' => max(0, self::TARGET - $sysAfter),
                    'overload_count' => max(0, $row['manual_other'] + $sysAfter - self::TARGET),
                    'generated_at' => $now,
                ];
                if ($alloc === null) {
                    ApkukShiftAllocation::create($values + [
                        'shift_instance_id' => $instance->getKey(),
                        'posko_id' => $row['posko_id'],
                        'generation_run_id' => $run->getKey(),
                    ]);
                } else {
                    $alloc->update($values);
                }
                $summary[] = [
                    'posko' => $row['posko'], 'posko_id' => $row['posko_id'],
                    'new' => count($created), 'created' => $created,
                    'system_total' => $sysAfter, 'manual_other' => $row['manual_other'],
                    'shortfall' => max(0, self::TARGET - $sysAfter),
                ];
            }
            $run->forceFill([
                'status' => ApkukGenerationRun::STATUS_COMPLETED,
                'summary' => $summary,
                // Do not include Eloquent models in persisted JSON.
                'held' => $preview['held'],
                'breakdown' => ['source' => 'INSPEKSI', 'eligible' => $preview['eligible'],
                    'source_total' => $preview['source_total'], 'held_count' => count($preview['held'])],
                'finished_at' => now(),
            ])->save();
            return $run;
        });
    }

    /** Count original shift production, not WO carried over into this shift by reschedule. */
    private function generatedForOriginalShift(string $date, string $shift, ?string $poskoId = null): \Illuminate\Database\Eloquent\Builder
    {
        return WorkOrder::query()->apkuk()
            ->when($poskoId !== null, fn ($q) => $q->where('posko_id', $poskoId))
            ->where('source', WorkOrder::SOURCE_SYSTEM)
            ->whereHas('generationRun.shiftInstance', fn ($q) => $q
                ->whereDate('operational_date', $date)->where('shift_code', $shift));
    }

    private function workOrderQuery(string $date, string $shift, string $poskoId): \Illuminate\Database\Eloquent\Builder
    {
        return WorkOrder::query()->apkuk()->whereDate('work_date', $date)
            ->where('shift_code', $shift)->where('posko_id', $poskoId);
    }

    private function validateShift(string $date, string $shift): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! in_array($shift, $this->shifts->codes(), true)) {
            throw new LogicException('Tanggal operasional atau shift tidak sah.');
        }
        // Reject normalized invalid dates instead of silently rolling forward.
        if (CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new LogicException('Tanggal operasional tidak valid.');
        }
    }

    private function assertIsolatedTestDatabase(): void
    {
        $connection = config('database.connections.sqlite');
        if (app()->environment() !== 'testing'
            || config('database.default') !== 'sqlite'
            || ($connection['driver'] ?? null) !== 'sqlite'
            || ($connection['database'] ?? null) !== ':memory:'
            || filled($connection['url'] ?? null)
            || ! config('apkuk.generator.enabled')) {
            throw new RuntimeException('PILOT V6A DITOLAK: hanya testing + SQLite :memory: dengan flag tes eksplisit.');
        }
    }
}
