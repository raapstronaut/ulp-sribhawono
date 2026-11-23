<?php

namespace App\Services\Row;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** V12 Admin preview of persisted plans. Enforces local PG read-only transaction. */
final class RowPlanningReadOnlyPreview
{
    public function __construct(
        private readonly RowInspectionPlanService $plans,
        private readonly RowDailyPreviewPlanner $planner,
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $date): array
    {
        $mode = $this->safeDatabaseMode();
        DB::beginTransaction();
        try {
            if ($mode === 'pgsql') {
                // PostgreSQL itself refuses UPDATE/INSERT/DELETE/DDL in this TX.
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::statement('SET TRANSACTION READ ONLY');
                $actual = DB::selectOne("SELECT current_database() AS db_name,
                    host(inet_server_addr()) AS ip, inet_server_port() AS port,
                    current_schema() AS schema_name,
                    current_setting('transaction_read_only') AS read_only,
                    current_setting('transaction_isolation') AS isolation");
                if ($actual === null || $actual->db_name !== 'ulp_sribhawono_dev'
                    || ! in_array((string) $actual->ip, ['127.0.0.1', '::1'], true)
                    || (int) $actual->port !== 5432
                    || $actual->schema_name !== 'public'
                    || $actual->read_only !== 'on' || $actual->isolation !== 'repeatable read') {
                    throw new RuntimeException('ROW V12 DITOLAK: identitas server atau transaksi read-only tidak cocok.');
                }
            }

            // Both reads occur inside the same consistent transaction scope.
            $assignments = $this->plans->assignments();
            $report = $this->planner->preview($date, $assignments);
            $report['persisted_admin_assignments'] = count($assignments);
            $report['strategy'] = 'ROW_INSPEKSI_V12_ADMIN_READONLY';
            $report['generation_enabled'] = false;

            return $report;
        } finally {
            // Never commit a preview, even in SQLite tests.
            DB::rollBack();
        }
    }

    private function safeDatabaseMode(): string
    {
        $env = (string) app()->environment();
        $conn = (string) config('database.default');
        $db = (array) config('database.connections.'.$conn, []);
        if (filled($db['url'] ?? null) || (string) getenv('DB_URL') !== ''
            || (string) getenv('DATABASE_URL') !== '') {
            throw new RuntimeException('ROW V12 DITOLAK: URL database tidak diizinkan.');
        }
        if ($env === 'testing' && $conn === 'sqlite'
            && ($db['driver'] ?? null) === 'sqlite'
            && ($db['database'] ?? null) === ':memory:') {
            return 'sqlite';
        }
        if ($env === 'local' && $conn === 'pgsql'
            && ($db['driver'] ?? null) === 'pgsql'
            && in_array((string) ($db['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            && (string) ($db['port'] ?? '') === '5432'
            && ($db['database'] ?? null) === 'ulp_sribhawono_dev') {
            return 'pgsql';
        }
        throw new RuntimeException('ROW V12 DITOLAK: hanya PostgreSQL dev localhost atau SQLite :memory:.');
    }
}
