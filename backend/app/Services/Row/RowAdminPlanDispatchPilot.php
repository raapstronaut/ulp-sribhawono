<?php

namespace App\Services\Row;

use RuntimeException;

/**
 * V13: persisted Admin ROW decisions -> actual SYSTEM WOs in an ISOLATED test only.
 * Deliberately no routes, commands, scheduler, or PostgreSQL write capability.
 * Live dispatch will require a separate concurrency-safe implementation and approval.
 */
final class RowAdminPlanDispatchPilot
{
    public function __construct(
        private readonly RowInspectionPlanService $plans,
        private readonly RowDailyGeneratorPilot $pilot,
    ) {}

    /**
     * @return array{created: array<int, string>, count: int}
     */
    public function generateForIsolatedTest(string $date): array
    {
        // The test pilot performs its own gate as defense-in-depth. This gate
        // prevents even reading persisted assignments outside isolated tests.
        $sqlite = (array) config('database.connections.sqlite', []);
        if (! app()->environment('testing')
            || config('database.default') !== 'sqlite'
            || ($sqlite['driver'] ?? null) !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)
            || config('row.pilot_generation_enabled') !== true
            || (string) getenv('DB_URL') !== ''
            || (string) getenv('DATABASE_URL') !== '') {
            throw new RuntimeException('V13 ROW DISPATCH PILOT DITOLAK: hanya testing + SQLite :memory: dengan flag tes eksplisit.');
        }

        return $this->pilot->generateForIsolatedTest($date, $this->plans->assignments());
    }
}
