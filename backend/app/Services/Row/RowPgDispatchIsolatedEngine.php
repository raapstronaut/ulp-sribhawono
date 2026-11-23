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
use LogicException;
use PDO;
use RuntimeException;

/**
 * V14 STAGED: PostgreSQL transaction/locking implementation for the V15
 * concurrency exercise. NO route, CLI command, scheduler or live activation.
 * This class can only write to the dedicated, isolated PostgreSQL test database
 * after TWO explicit test-only gates. Local dev, Neon, and production are refused.
 *
 * This is NOT yet proof of concurrent safety against manual ROW creators.
 * Their separate write path must be coordinated and concurrency-tested in V15.
 */
final class RowPgDispatchIsolatedEngine
{
    public const TEST_DATABASE = 'ulp_row_concurrency_test';

    public const STRATEGY = 'ROW_PG_V14_CONCURRENCY_STAGED_TEST_ONLY';

    private const REQUIRED_OPT_IN = 'ISOLATED_ROW_PG_TEST_ONLY';

    public function __construct(
        private readonly RowInspectionPlanService $plans,
        private readonly RowDailyPreviewPlanner $planner,
        private readonly LocationTargetResolver $resolver,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkOrderLifecycleService $lifecycle,
    ) {}

    /**
     * ONLY for the separate PostgreSQL concurrency test database, NOT dev/live.
     * Fails before opening any database connection unless all local gates match.
     *
     * @return array{created: array<int, string>, count: int}
     */
    public function runInIsolatedPostgresTest(string $date): array
    {
        $this->assertConfiguredForIsolatedTest();
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new LogicException('Tanggal kerja ROW tidak valid.');
        }
        if (CarbonImmutable::parse($date, 'Asia/Jakarta')->endOfDay()->lt(CarbonImmutable::now('Asia/Jakarta'))) {
            throw new LogicException('Tanggal ROW sudah berakhir; tidak boleh menerbitkan WO.');
        }

        // One transaction per batch. An exception rolls back EVERY WO, target,
        // finding link, and log created in this run. No partial batch success.
        return DB::transaction(function () use ($date): array {
            $this->assertActualIsolatedServer();

            // Serialize V14 test dispatchers across dates and teams, including
            // their WO code sequence. Transaction-scoped lock auto-releases.
            // This lock is NOT yet shared by the existing manual WO path.
            DB::select('SELECT pg_advisory_xact_lock(210014, 14)');

            // Match RowInspectionPlanService::save lock order: finding then plan.
            // Lock all currently ROW-directed findings, not just approved, so a
            // concurrent change of validation status cannot race this batch.
            InspectionFinding::query()
                ->where('suggested_team_type', WorkOrder::TEAM_ROW)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('row_inspection_plans')
                ->orderBy('inspection_finding_id')->lockForUpdate()->get(['inspection_finding_id']);
            FieldTeam::query()->where('role', WorkOrder::TEAM_ROW)
                ->orderBy('id')->lockForUpdate()->get(['id']);

            // Preview is recomputed under the locks: never trust browser data.
            $preview = $this->planner->preview($date, $this->plans->assignments());
            $created = [];
            foreach ($preview['rows'] as $teamRow) {
                foreach ($teamRow['planned'] as $item) {
                    $created[] = $this->createOne($date, $item);
                }
            }

            return ['created' => $created, 'count' => count($created)];
        }, 1);
    }

    /**
     * Public only for testing the fail-closed gates without contacting Postgres.
     */
    public function assertConfiguredForIsolatedTest(): void
    {
        $connection = (string) config('database.default');
        $cfg = (array) config('database.connections.'.$connection, []);
        if (! app()->environment('testing')
            || $connection !== 'pgsql'
            || ($cfg['driver'] ?? null) !== 'pgsql'
            || ($cfg['database'] ?? null) !== self::TEST_DATABASE
            || ! in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            || (string) ($cfg['port'] ?? '') !== '5432'
            || filled($cfg['url'] ?? null)
            || (string) getenv('DB_URL') !== ''
            || (string) getenv('DATABASE_URL') !== ''
            || config('row.pg_concurrency_test_enabled') !== true
            || (string) getenv('ROW_PG_CONCURRENCY_TEST_APPROVED') !== self::REQUIRED_OPT_IN) {
            throw new RuntimeException(
                'V14 ROW PG DITOLAK: hanya APP_ENV testing + database ulp_row_concurrency_test ' .
                'localhost:5432 + dua izin uji eksplisit; database operasional tidak diizinkan.'
            );
        }
    }

    private function assertActualIsolatedServer(): void
    {
        $pdo = DB::connection()->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('V14 ROW PG DITOLAK: koneksi aktual bukan PostgreSQL.');
        }
        $server = DB::selectOne("SELECT current_database() AS db_name,
            host(inet_server_addr()) AS ip, inet_server_port() AS port,
            current_schema() AS schema_name, current_setting('transaction_read_only') AS read_only");
        if ($server === null || $server->db_name !== self::TEST_DATABASE
            || ! in_array((string) $server->ip, ['127.0.0.1', '::1'], true)
            || (int) $server->port !== 5432
            || $server->schema_name !== 'public'
            || $server->read_only !== 'off') {
            throw new RuntimeException('V14 ROW PG DITOLAK: identitas server, skema, atau transaksi tidak sesuai.');
        }
    }

    /** @param array<string, mixed> $item */
    private function createOne(string $date, array $item): string
    {
        $finding = InspectionFinding::query()->findOrFail($item['finding_id']);
        $team = FieldTeam::query()->whereKey($item['field_team_id'])
            ->where('role', WorkOrder::TEAM_ROW)->where('is_active', true)->first();
        $plan = DB::table('row_inspection_plans')
            ->where('inspection_finding_id', $finding->getKey())->first();
        if ($finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
            || $finding->suggested_team_type !== WorkOrder::TEAM_ROW
            || $finding->follow_up_work_order_id !== null || $team === null || $plan === null
            || $plan->field_team_id !== $team->getKey()
            || $plan->work_type !== $item['work_type']
            || ! in_array($item['work_type'], WorkOrder::ROW_WORK_TYPES, true)) {
            throw new LogicException('Rencana/temuan/tim berubah; seluruh batch ROW dibatalkan.');
        }

        $existing = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
            ->where('source', WorkOrder::SOURCE_SYSTEM)
            ->where('field_team_id', $team->getKey())
            ->whereDate('work_date', $date)->count();
        if ($existing >= RowDailyPreviewPlanner::DAILY_TARGET_PER_TEAM) {
            throw new LogicException('Kuota SYSTEM ROW per tim/hari sudah penuh.');
        }

        $inspection = $finding->inspection()->with('penyulang')->firstOrFail();
        $feeder = $inspection->penyulang;
        if ($feeder === null || ! $feeder->is_active) {
            throw new LogicException('Penyulang berubah/tidak aktif; seluruh batch dibatalkan.');
        }
        $target = $this->resolver->fromFinding($finding, $feeder);
        if ($target['target_key'] !== $item['target']['target_key']) {
            throw new LogicException('Identitas target berubah; seluruh batch dibatalkan.');
        }

        // A final collision check across ROW subtypes; the unique index on
        // active_target_key is only an ADDITIONAL guard for the same subtype.
        $active = WorkOrderLocationTarget::query()->whereNotNull('active_target_key')
            ->whereHas('workOrder', fn ($q) => $q->where('team_type', WorkOrder::TEAM_ROW))
            ->get(['target_key', 'latitude', 'longitude']);
        foreach ($active as $location) {
            if ($location->target_key === $target['target_key']
                || ($target['latitude'] !== null && $target['longitude'] !== null
                    && $location->latitude !== null && $location->longitude !== null
                    && abs((float) $location->latitude - (float) $target['latitude']) < 0.0000005
                    && abs((float) $location->longitude - (float) $target['longitude']) < 0.0000005)) {
                throw new LogicException('ROW aktif pada titik yang sama; seluruh batch dibatalkan.');
            }
        }

        $wo = WorkOrder::create([
            'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_ROW, CarbonImmutable::now()),
            'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => $item['work_type'],
            'source' => WorkOrder::SOURCE_SYSTEM,
            'status' => WorkOrderStatus::Ditugaskan,
            'field_team_id' => $team->getKey(),
            'penyulang_id' => $feeder->getKey(),
            'section_name' => $inspection->section_name,
            'work_date' => $date,
            // Work start/due dates have no approved rule: never fabricate them.
            'notes' => $finding->description,
        ]);
        WorkOrderLocationTarget::create($target + [
            'work_order_id' => $wo->getKey(),
            'active_target_key' => implode('|', [WorkOrder::TEAM_ROW, $item['work_type'], $target['target_key']]),
        ]);
        $finding->forceFill(['follow_up_work_order_id' => $wo->getKey()])->save();
        $this->lifecycle->log($wo, WorkOrderStatusLog::ACTION_CREATED,
            null, WorkOrderStatus::Ditugaskan, null, null, [
                'origin' => 'INSPEKSI',
                'source' => WorkOrder::SOURCE_SYSTEM,
                'inspection_id' => $inspection->getKey(),
                'inspection_finding_id' => $finding->getKey(),
                'field_team_id' => $team->getKey(),
                'work_type' => $item['work_type'],
                'work_date' => $date,
                'strategy' => self::STRATEGY,
                'assignment_origin' => 'PERSISTED_ADMIN_PLAN',
            ]);

        return (string) $wo->getKey();
    }
}
