<?php

namespace App\Services\Row;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\WorkOrderCodeGenerator;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * V9 isolated ROW pilot, not a production generator.
 *
 * The INSPEKSI form identifies the destination TEAM ROLE (ROW), but has no
 * persisted work subtype (RABAS/PANGKAS/TEBANG) or receiving ROW team. Neither
 * may be inferred from a free-text description or from vegetation staging.
 * Test generation stays isolated to SQLite :memory:. The shared preview reader
 * also supports a separately guarded, READ ONLY Admin preview on local PostgreSQL.
 * This class has no route, CLI, scheduler or PostgreSQL generation capability.
 */
final class RowDailyGeneratorPilot
{
    public const DAILY_TARGET_PER_TEAM = 10;

    public const STRATEGY = 'ROW_INSPEKSI_V9_ISOLATED_PILOT';

    public function __construct(
        private readonly LocationTargetResolver $resolver,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly RowDailyPreviewPlanner $planner,
    ) {}

    /**
     * @param array<string, array{field_team_id?: string, work_type?: string}> $assignments
     * @return array<string, mixed>
     */
    public function preview(string $date, array $assignments = []): array
    {
        $this->assertIsolatedDatabase(false);

        return $this->planner->preview($date, $assignments);
    }

    /**
     * Explicit simulated assignment is never persisted as a policy.
     * Never call with local PostgreSQL, production, or file-backed SQLite.
     *
     * @param array<string, array{field_team_id?: string, work_type?: string}> $assignments
     * @return array{created: array<int, string>, count: int}
     */
    public function generateForIsolatedTest(string $date, array $assignments): array
    {
        $this->assertIsolatedDatabase(true);
        $this->assertValidDate($date);
        if (CarbonImmutable::parse($date, 'Asia/Jakarta')->endOfDay()->lt(CarbonImmutable::now('Asia/Jakarta'))) {
            throw new LogicException('Tanggal simulasi ROW sudah berakhir.');
        }

        return DB::transaction(function () use ($date, $assignments): array {
            $preview = $this->preview($date, $assignments);
            $created = [];
            foreach ($preview['rows'] as $row) {
                foreach ($row['planned'] as $candidate) {
                    $finding = InspectionFinding::query()->lockForUpdate()
                        ->findOrFail($candidate['finding_id']);
                    if ($finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
                        || $finding->suggested_team_type !== WorkOrder::TEAM_ROW
                        || $finding->follow_up_work_order_id !== null) {
                        throw new LogicException('Status temuan berubah; seluruh simulasi dibatalkan.');
                    }
                    $team = FieldTeam::query()->whereKey($candidate['field_team_id'])
                        ->where('role', WorkOrder::TEAM_ROW)->where('is_active', true)->first();
                    if ($team === null) {
                        throw new LogicException('Tim ROW tidak aktif; seluruh simulasi dibatalkan.');
                    }
                    $inspection = $finding->inspection()->with('penyulang')->firstOrFail();
                    if ($inspection->penyulang === null || ! $inspection->penyulang->is_active) {
                        throw new LogicException('Penyulang tidak aktif; simulasi dibatalkan.');
                    }
                    $target = $this->resolver->fromFinding($finding, $inspection->penyulang);
                    if ($target['target_key'] !== $candidate['target']['target_key']) {
                        throw new LogicException('Target temuan berubah; simulasi dibatalkan.');
                    }
                    // The fresh preview and unique active_target_key protect this
                    // isolated sequential simulation. PostgreSQL concurrency safety
                    // is explicitly OUT OF SCOPE for V9.
                    $wo = WorkOrder::create([
                        'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_ROW, CarbonImmutable::now()),
                        'team_type' => WorkOrder::TEAM_ROW,
                        'work_type' => $candidate['work_type'],
                        'source' => WorkOrder::SOURCE_SYSTEM,
                        'status' => WorkOrderStatus::Ditugaskan,
                        'field_team_id' => $team->getKey(),
                        'penyulang_id' => $inspection->penyulang->getKey(),
                        'section_name' => $inspection->section_name,
                        'work_date' => $date,
                        // Start/due times are not invented; ROW scheduling rules
                        // have not been approved for live dispatch.
                        'notes' => $finding->description,
                    ]);
                    WorkOrderLocationTarget::create($target + [
                        'work_order_id' => $wo->getKey(),
                        'active_target_key' => implode('|', [WorkOrder::TEAM_ROW, $candidate['work_type'], $target['target_key']]),
                    ]);
                    $finding->forceFill(['follow_up_work_order_id' => $wo->getKey()])->save();
                    $this->lifecycle->log($wo, WorkOrderStatusLog::ACTION_CREATED, null,
                        WorkOrderStatus::Ditugaskan, null, null, [
                            'source' => WorkOrder::SOURCE_SYSTEM,
                            'origin' => 'INSPEKSI',
                            'inspection_id' => $inspection->getKey(),
                            'inspection_finding_id' => $finding->getKey(),
                            'field_team_id' => $team->getKey(),
                            'work_type' => $candidate['work_type'],
                            'work_date' => $date,
                            'strategy' => self::STRATEGY,
                            'assignment_origin' => 'EXPLICIT_TEST_PLAN',
                        ]);
                    $created[] = (string) $wo->getKey();
                }
            }
            return ['created' => $created, 'count' => count($created)];
        });
    }

    private function assertIsolatedDatabase(bool $write): void
    {
        $sqlite = (array) config('database.connections.sqlite');
        if (! app()->environment('testing')
            || config('database.default') !== 'sqlite'
            || ($sqlite['driver'] ?? null) !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)
            || filled(config('database.connections.sqlite.url'))
            || ($write && config('row.pilot_generation_enabled') !== true)) {
            throw new RuntimeException('PILOT ROW V9 DITOLAK: hanya testing + SQLite :memory:; generate perlu flag tes eksplisit.');
        }
    }

    private function assertValidDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new LogicException('Tanggal ROW tidak valid.');
        }
    }
}
