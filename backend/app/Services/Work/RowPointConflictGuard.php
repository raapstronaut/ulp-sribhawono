<?php

namespace App\Services\Work;

use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Shared PostgreSQL ROW lock for manual authors and isolated ROW generator.
 * The V14 generator already takes pg_advisory_xact_lock(210014, 14).
 * PostgreSQL sessions cannot pass the same transaction-scoped lock concurrently.
 * Lock must be acquired before inspection_findings/ROW plan row locks.
 */
final class RowPointConflictGuard
{
    public const LOCK_KEY1 = 210014;
    public const LOCK_KEY2 = 14;

    public function lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // SQLite in-memory regression tests do not have advisory locks.
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('ROW lock harus berada dalam transaksi database.');
        }
        DB::select('SELECT pg_advisory_xact_lock(210014, 14)');
    }

    /** @param array<string, mixed> $target */
    public function assertNoActiveRowAtPoint(array $target): void
    {
        $key = (string) ($target['target_key'] ?? '');
        if ($key === '') {
            throw new LogicException('Identitas titik ROW tidak valid.');
        }
        $latitude = $target['latitude'] ?? null;
        $longitude = $target['longitude'] ?? null;

        // Same rule as ROW generator planner: all ROW work types, not just
        // identical subtype. No query relying only on the per-subtype unique key.
        $active = WorkOrderLocationTarget::query()->whereNotNull('active_target_key')
            ->whereHas('workOrder', fn ($q) => $q->where('team_type', WorkOrder::TEAM_ROW))
            ->get(['target_key', 'target_type', 'latitude', 'longitude']);

        foreach ($active as $location) {
            $sameKey = (string) $location->target_key === $key;
            // Two different ID POHON values are separate vegetation targets,
            // even when the GPS snapshot is shared by trees near one pole.
            // Coordinate deduplication remains active for inspection-only targets
            // or for mixed/legacy identities without separate verified tree IDs.
            $bothIdentifiedTrees = ($target['target_type'] ?? null) === WorkOrderLocationTarget::TYPE_VEGETASI
                && $location->target_type === WorkOrderLocationTarget::TYPE_VEGETASI
                && str_starts_with($key, 'POHON:')
                && str_starts_with((string) $location->target_key, 'POHON:');
            $sameCoords = ! $bothIdentifiedTrees
                && $latitude !== null && $longitude !== null
                && $location->latitude !== null && $location->longitude !== null
                && abs((float) $latitude - (float) $location->latitude) < 0.0000005
                && abs((float) $longitude - (float) $location->longitude) < 0.0000005;
            if ($sameKey || $sameCoords) {
                throw ValidationException::withMessages([
                    'target' => 'Titik ini sudah memiliki WO ROW aktif. Selesaikan atau batalkan WO sebelumnya.',
                ]);
            }
        }
    }
}
