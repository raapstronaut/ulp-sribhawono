<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ringkasan analitik baca saja dari tabel yang sudah tersedia.
 * KPI domain yang belum dibangun (PASILA, P0, Inspeksi) tidak dihitung.
 */
class AnalyticsController extends Controller
{
    public const PERIODS = [30 => '30 hari', 90 => '90 hari', 180 => '180 hari', 365 => '365 hari'];

    private const DEFAULT_PERIOD = 90;

    private const TOP_LIMIT = 10;

    public function index(Request $request, ShiftService $shifts, DataLoader $load): View
    {
        $period = QueryInput::integerChoice($request, 'period', array_keys(self::PERIODS), self::DEFAULT_PERIOD);
        $today = CarbonImmutable::parse($shifts->today(), ShiftService::TIMEZONE);
        $range = ['from' => $today->subDays($period - 1)->toDateString(), 'to' => $today->toDateString()];

        $gangguanInRange = fn () => DB::table('gangguans')->whereBetween('tanggal_kejadian', [$range['from'], $range['to']]);
        // Portabel PostgreSQL/SQLite: tanggal ISO "YYYY-MM-DD" → "YYYY-MM".
        $month = 'substr(cast(tanggal_kejadian as text), 1, 7)';

        return view('admin.analytics.index', [
            'period' => $period,
            'range' => $range,
            'hasFilter' => $request->has('period'),
            'workOrderMatrix' => $load->attempt('work_orders', fn () => WorkOrder::query()
                ->whereBetween('work_date', [$range['from'], $range['to']])
                ->select('team_type', 'status', DB::raw('count(*) as total'))
                ->groupBy('team_type', 'status')
                ->get()
                ->groupBy('team_type')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->total])->all())
                ->all(), []),
            'statuses' => WorkOrderStatus::cases(),
            'gangguanPerMonth' => $load->attempt('gangguan_month', fn () => $gangguanInRange()
                ->select(DB::raw("{$month} as bulan"), DB::raw('count(*) as total'))
                ->groupBy(DB::raw($month))
                ->orderBy('bulan')
                ->get(), collect()),
            'gangguanPerPenyulang' => $load->attempt('gangguan_penyulang', fn () => $gangguanInRange()
                ->select('penyulang', DB::raw('count(*) as total'))
                ->groupBy('penyulang')
                ->orderByDesc('total')
                ->orderBy('penyulang')
                ->limit(self::TOP_LIMIT)
                ->get(), collect()),
            'gangguanPerPenyebab' => $load->attempt('gangguan_penyebab', fn () => $gangguanInRange()
                ->select('penyebab', DB::raw('count(*) as total'))
                ->groupBy('penyebab')
                ->orderByDesc('total')
                ->orderBy('penyebab')
                ->limit(self::TOP_LIMIT)
                ->get(), collect()),
            'apkukStatus' => $load->attempt('apkuks', fn () => DB::table('apkuks')
                ->whereNull('source_missing_at')
                ->select('status', DB::raw('count(*) as total'), DB::raw('sum(kurang_bh) as kurang'))
                ->groupBy('status')
                ->orderByDesc('total')
                ->get(), collect()),
            'keypointJenis' => $load->attempt('keypoints', fn () => DB::table('keypoints')
                ->select('jenis', DB::raw('count(*) as total'), DB::raw('sum(case when latitude is not null and longitude is not null then 1 else 0 end) as mapped'))
                ->groupBy('jenis')
                ->orderByDesc('total')
                ->get(), collect()),
            'load' => $load,
        ]);
    }
}
