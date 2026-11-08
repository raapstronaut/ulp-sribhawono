<?php

namespace App\Services\Work;

use App\Models\WorkOrder;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;

/**
 * Kode WO: WO-{JENIS}-YYYYMMDD-NNNN (tanggal pembuatan WIB, nomor urut harian per jenis).
 * Bentrok karena request bersamaan ditangani pemanggil lewat unique index `code`.
 */
class WorkOrderCodeGenerator
{
    public const KIND_APKUK = 'APKUK';

    public const KIND_HARSUS = 'HARSUS';

    public const KIND_ROW = 'ROW';

    public const KIND_P0 = 'P0';

    public static function kindFor(string $teamType): string
    {
        return match ($teamType) {
            WorkOrder::TEAM_HARSUS => self::KIND_HARSUS,
            WorkOrder::TEAM_ROW => self::KIND_ROW,
            default => self::KIND_APKUK,
        };
    }

    public function next(string $kind, CarbonImmutable $now): string
    {
        $prefix = 'WO-'.$kind.'-'.$now->setTimezone(ShiftService::TIMEZONE)->format('Ymd').'-';
        $last = WorkOrder::query()->where('code', 'like', $prefix.'%')->max('code');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
