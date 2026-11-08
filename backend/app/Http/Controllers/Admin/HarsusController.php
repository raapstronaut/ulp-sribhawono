<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Penyulang;
use App\Models\WorkOrder;
use App\Services\Admin\TeamWorkOrderService;
use App\Support\DataLoader;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monitor WO PEKERJAAN_KHUSUS HARSUS. HARSUS bukan pelaksana P0.
 */
class HarsusController extends Controller
{
    public function index(Request $request, TeamWorkOrderService $service, DataLoader $load): View
    {
        $filters = $service->filters($request);

        return view('admin.harsus.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'workOrders' => $load->attempt(
                'work_orders',
                fn () => $service->paginate(WorkOrder::TEAM_HARSUS, [WorkOrder::TYPE_PEKERJAAN_KHUSUS], $filters),
                DataLoader::emptyPage(TeamWorkOrderService::PER_PAGE),
            ),
            'summary' => $load->attempt('summary', fn () => $service->summary(WorkOrder::TEAM_HARSUS)),
            'penyulangOptions' => $load->attempt('penyulangs', fn () => Penyulang::query()
                ->where('is_active', true)
                ->orderBy('kode_penyulang')
                ->pluck('nama_penyulang', 'id')
                ->all(), []),
            'sourceOptions' => TeamWorkOrderService::SOURCES,
            'statusOptions' => $service->statusOptions(),
            'load' => $load,
        ]);
    }
}
