<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Services\Admin\WorkOrderAnalyticsService;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analitik & pelaporan dari data nyata: statistik WO (status, periode, Posko, penyulang,
 * jenis, ketepatan waktu) dengan ekspor CSV, serta gangguan, APKUK, dan Keypoint.
 * KPI PASILA dan P0 tidak dihitung karena domainnya belum dibangun.
 */
class AnalyticsController extends Controller
{
    public const PERIODS = [30 => '30 hari', 90 => '90 hari', 180 => '180 hari', 365 => '365 hari'];

    private const DEFAULT_PERIOD = 90;

    private const TOP_LIMIT = 10;

    public function index(Request $request, ShiftService $shifts, DataLoader $load, WorkOrderAnalyticsService $analytics): View
    {
        $period = QueryInput::integerChoice($request, 'period', array_keys(self::PERIODS), self::DEFAULT_PERIOD);
        $range = $this->range($period, $shifts);

        $gangguanInRange = fn () => DB::table('gangguans')->whereBetween('tanggal_kejadian', [$range['from'], $range['to']]);
        // Portabel PostgreSQL/SQLite: tanggal ISO "YYYY-MM-DD" → "YYYY-MM".
        $month = 'substr(cast(tanggal_kejadian as text), 1, 7)';

        $woFilters = $this->woFilters($request, $range);

        return view('admin.analytics.index', [
            'period' => $period,
            'range' => $range,
            'hasFilter' => $request->hasAny(['period', 'team_type', 'posko_id', 'penyulang_id']),
            'woFilters' => $woFilters,
            'woStats' => $load->attempt('wo_stats', fn () => $analytics->summarize($woFilters)),
            'poskoOptions' => Posko::query()->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(),
            'penyulangOptions' => Penyulang::query()->orderBy('kode_penyulang')->pluck('nama_penyulang', 'id')->all(),
            'workOrderMatrix' => $load->attempt('work_orders', fn () => $analytics->query($woFilters)
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

    /**
     * Ekspor CSV daftar WO pada periode dan filter yang sama dengan halaman Analitik.
     */
    public function export(Request $request, ShiftService $shifts, WorkOrderAnalyticsService $analytics): StreamedResponse
    {
        $period = QueryInput::integerChoice($request, 'period', array_keys(self::PERIODS), self::DEFAULT_PERIOD);
        $filters = $this->woFilters($request, $this->range($period, $shifts));
        $filename = 'laporan-wo-'.$filters['from'].'-sd-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($analytics, $filters): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8.
            // Nilai teks diawali = + - @ diberi apostrof agar tidak dieksekusi sebagai formula.
            $safe = fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
            foreach ($analytics->exportRows($filters) as $row) {
                fputcsv($handle, array_map($safe, $row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function range(int $period, ShiftService $shifts): array
    {
        $today = CarbonImmutable::parse($shifts->today(), ShiftService::TIMEZONE);

        return ['from' => $today->subDays($period - 1)->toDateString(), 'to' => $today->toDateString()];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{from: string, to: string, team_type: string, posko_id: string, penyulang_id: string}
     */
    private function woFilters(Request $request, array $range): array
    {
        return $range + [
            'team_type' => QueryInput::choice($request, 'team_type', array_keys(WorkOrder::TEAM_LABELS)),
            'posko_id' => QueryInput::uuid($request, 'posko_id'),
            'penyulang_id' => QueryInput::uuid($request, 'penyulang_id'),
        ];
    }
}
