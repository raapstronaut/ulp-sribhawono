<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Penyulang;
use App\Models\WorkOrder;
use App\Services\Admin\TeamWorkOrderService;
use App\Support\DataLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Monitor WO ROW (RABAS/PANGKAS/TEBANG). Generator otomatis dan rute harian belum dibangun.
 */
class RowController extends Controller
{
    public function index(Request $request, TeamWorkOrderService $service, DataLoader $load): View
    {
        $filters = $service->filters($request);

        return view('admin.row.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'workOrders' => $load->attempt(
                'work_orders',
                fn () => $service->paginate(WorkOrder::TEAM_ROW, WorkOrder::ROW_WORK_TYPES, $filters),
                DataLoader::emptyPage(TeamWorkOrderService::PER_PAGE),
            ),
            'summary' => $load->attempt('summary', fn () => $service->summary(WorkOrder::TEAM_ROW)),
            'vegetationSourceRows' => $load->attempt('vegetation', fn () => DB::table('vegetasi_source_rows')->count()),
            'penyulangOptions' => $load->attempt('penyulangs', fn () => Penyulang::query()
                ->where('is_active', true)
                ->orderBy('kode_penyulang')
                ->pluck('nama_penyulang', 'id')
                ->all(), []),
            'workTypeOptions' => array_combine(WorkOrder::ROW_WORK_TYPES, WorkOrder::ROW_WORK_TYPES),
            'statusOptions' => $service->statusOptions(),
            'load' => $load,
        ]);
    }
}
