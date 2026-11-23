<?php

namespace Tests\Feature\Operations;

use App\Models\WorkOrder;
use App\Services\Row\RowPgDispatchIsolatedEngine;
use RuntimeException;

/** V14 checks fail-closed PostgreSQL test gates under SQLite :memory:. */
final class RowPgDispatchIsolatedEngineTest extends OperationsTestCase
{
    public function test_pg_dispatch_refuses_sqlite_even_when_both_test_flags_are_set(): void
    {
        config(['row.pg_concurrency_test_enabled' => true]);
        $prior = getenv('ROW_PG_CONCURRENCY_TEST_APPROVED');
        try {
            putenv('ROW_PG_CONCURRENCY_TEST_APPROVED=ISOLATED_ROW_PG_TEST_ONLY');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('V14 ROW PG DITOLAK');
            app(RowPgDispatchIsolatedEngine::class)->runInIsolatedPostgresTest('2026-11-23');
        } finally {
            $prior === false
                ? putenv('ROW_PG_CONCURRENCY_TEST_APPROVED')
                : putenv('ROW_PG_CONCURRENCY_TEST_APPROVED='.$prior);
        }
    }

    public function test_pg_dispatch_refuses_without_explicit_opt_in_and_does_not_write_wo(): void
    {
        config(['row.pg_concurrency_test_enabled' => false]);
        try {
            app(RowPgDispatchIsolatedEngine::class)->runInIsolatedPostgresTest('2026-11-23');
            $this->fail('V14 should fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('V14 ROW PG DITOLAK', $e->getMessage());
        }
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_production_named_pg_database_is_refused_before_any_connection(): void
    {
        $original = config('database.default');
        $originalPgsql = config('database.connections.pgsql');
        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql' => array_merge((array) $originalPgsql, [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432',
            'database' => 'ulp_sribhawono_dev', 'url' => null,
        ])]);
        try {
            app(RowPgDispatchIsolatedEngine::class)->assertConfiguredForIsolatedTest();
            $this->fail('Operational PostgreSQL must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('V14 ROW PG DITOLAK', $e->getMessage());
        } finally {
            config(['database.default' => $original]);
            config(['database.connections.pgsql' => $originalPgsql]);
        }
        $this->assertSame(0, WorkOrder::query()->count());
    }
}
