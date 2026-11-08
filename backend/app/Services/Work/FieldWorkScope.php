<?php

namespace App\Services\Work;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cakupan WO yang boleh dilihat dan dikerjakan akun lapangan:
 * - POSKO: WO POSKO milik Posko timnya.
 * - HARSUS / ROW: WO tim sejenis.
 * WO yang sudah ditunjuk ke tim tertentu hanya terlihat oleh tim tersebut.
 * INSPEKSI tidak menerima WO.
 */
class FieldWorkScope
{
    public static function query(User $account): Builder
    {
        $team = $account->fieldTeam;
        $query = WorkOrder::query();

        if ($team === null || ! in_array($team->role, [WorkOrder::TEAM_POSKO, WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW], true)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('team_type', $team->role)
            ->where(fn (Builder $q) => $q->whereNull('field_team_id')->orWhere('field_team_id', $team->getKey()));

        if ($team->role === WorkOrder::TEAM_POSKO) {
            $query->where('posko_id', $team->posko_id);
        }

        return $query;
    }

    public static function find(User $account, string $workOrderId): ?WorkOrder
    {
        return self::query($account)->whereKey($workOrderId)->first();
    }
}
