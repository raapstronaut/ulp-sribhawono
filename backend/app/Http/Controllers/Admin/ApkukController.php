<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Penyulang;
use App\Models\WorkOrder;
use App\Services\ApkukCandidateService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * APKUK: laporan sumber SPS BOS yang aktif (hasil sinkronisasi dan rekonsiliasi), histori
 * laporan yang tidak lagi ada di sumber (source_missing_at), kebutuhan pemasangan, dan
 * progres pemasangan dari WO. Tidak menulis ke apkuks maupun Google Sheets.
 */
class ApkukController extends Controller
{
    private const PER_PAGE = 25;

    public const TABS = ['aktif' => 'Laporan aktif', 'histori' => 'Histori (tidak ada di sumber)', 'progres' => 'Progres pemasangan WO'];

    public function index(Request $request, ApkukCandidateService $candidates, DataLoader $load): View
    {
        $tab = QueryInput::choice($request, 'tab', array_keys(self::TABS), 'aktif');
        $filters = [
            'penyulang' => QueryInput::text($request, 'penyulang', 100, trim: false),
            'status' => QueryInput::text($request, 'status', 60, trim: false),
            'q' => QueryInput::text($request, 'q'),
        ];
        $like = '%'.mb_strtoupper($filters['q']).'%';

        $evaluated = $load->attempt('candidates', fn () => $candidates->evaluate(), collect());
        $summary = $load->attempt('summary', fn () => [
            'active' => DB::table('apkuks')->whereNull('source_missing_at')->count(),
            'history' => DB::table('apkuks')->whereNotNull('source_missing_at')->count(),
            'last_synced_at' => DB::table('apkuks')->max('last_synced_at'),
            'eligible' => $evaluated->filter->isEligible()->count(),
            'need_bh' => (int) $evaluated->filter->isEligible()->sum(fn ($c) => $c->targetQuantity()),
            'wo_active' => WorkOrder::query()->apkuk()->active()->count(),
            'installed_bh' => (int) DB::table('work_order_apkuk_targets')->sum('installed_quantity'),
            'wo_done' => WorkOrder::query()->apkuk()->where('status', WorkOrderStatus::Selesai->value)->count(),
        ]);

        $rows = null;
        if ($tab !== 'progres') {
            $rows = $load->attempt('rows', fn () => DB::table('apkuks')
                ->when($tab === 'aktif', fn ($q) => $q->whereNull('source_missing_at'), fn ($q) => $q->whereNotNull('source_missing_at'))
                ->when($filters['penyulang'] !== '', fn ($q) => $q->where('penyulang', $filters['penyulang']))
                ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(no_tiang)'), 'like', $like)
                    ->orWhere(DB::raw('upper(source_id)'), 'like', $like)))
                ->orderByDesc('tanggal')
                ->orderBy('no_tiang')
                ->paginate(self::PER_PAGE, ['id', 'source_id', 'tanggal', 'penyulang', 'section', 'no_tiang', 'terpasang_bh', 'kurang_bh', 'status', 'latitude', 'longitude', 'source_missing_at'])
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
        } else {
            $rows = $load->attempt('rows', fn () => WorkOrder::query()->apkuk()
                ->with(['apkukTarget', 'posko'])
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(code)'), 'like', $like)
                    ->orWhereHas('apkukTarget', fn ($t) => $t->where(DB::raw('upper(no_tiang)'), 'like', $like))))
                ->orderByDesc('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
        }

        return view('admin.apkuk.index', [
            'tab' => $tab,
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($v) => $v !== ''),
            'summary' => $summary,
            'rows' => $rows,
            'candidates' => $evaluated,
            'issueLabels' => ApkukCandidateService::LABELS,
            'penyulangOptions' => $load->attempt('penyulangs', fn () => DB::table('apkuks')->whereNotNull('penyulang')->distinct()->orderBy('penyulang')->pluck('penyulang', 'penyulang')->all(), []),
            'statusOptions' => $load->attempt('statuses', fn () => DB::table('apkuks')->whereNotNull('status')->distinct()->orderBy('status')->pluck('status', 'status')->all(), []),
            'masterPenyulangCount' => Penyulang::query()->where('is_active', true)->count(),
            'load' => $load,
        ]);
    }
}
