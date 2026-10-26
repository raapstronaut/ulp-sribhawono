<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReschedule;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pusat Review & Validasi (baca saja).
 *
 * Antrean nyata: laporan WO yang menunggu verifikasi dan pengajuan reschedule.
 * Keputusan ACC/Tolak belum diaktifkan karena workflow dan auditnya belum lengkap.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 15;

    public const CATEGORIES = [
        'reports' => 'Laporan menunggu verifikasi',
        'reschedules' => 'Pengajuan reschedule',
    ];

    public const TEAM_TYPES = [
        WorkOrder::TEAM_POSKO => 'POSKO',
        WorkOrder::TEAM_HARSUS => 'HARSUS',
        WorkOrder::TEAM_ROW => 'ROW',
    ];

    public function index(Request $request, DataLoader $load): View
    {
        $filters = [
            'category' => QueryInput::choice($request, 'category', array_keys(self::CATEGORIES)),
            'team_type' => QueryInput::choice($request, 'team_type', array_keys(self::TEAM_TYPES)),
            'posko_id' => QueryInput::uuid($request, 'posko_id'),
        ];

        $constrainWorkOrder = function (Builder $query) use ($filters): void {
            if ($filters['team_type'] !== '') {
                $query->where('team_type', $filters['team_type']);
            }
            if ($filters['posko_id'] !== '') {
                $query->where('posko_id', $filters['posko_id']);
            }
        };

        $showReports = in_array($filters['category'], ['', 'reports'], true);
        $showReschedules = in_array($filters['category'], ['', 'reschedules'], true);

        return view('admin.reviews.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'showReports' => $showReports,
            'showReschedules' => $showReschedules,
            'reports' => $showReports ? $load->attempt('reports', fn () => WorkOrderReport::query()
                ->with(['workOrder.posko', 'submittedByTeam'])
                ->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)
                ->whereHas('workOrder', $constrainWorkOrder)
                ->orderBy('created_at')
                ->paginate(self::PER_PAGE, ['*'], 'reports_page')
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE, 'reports_page')) : null,
            'reschedules' => $showReschedules ? $load->attempt('reschedules', fn () => WorkOrderReschedule::query()
                ->with(['workOrder.posko', 'requestedByTeam'])
                ->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)
                ->whereHas('workOrder', $constrainWorkOrder)
                ->orderBy('created_at')
                ->paginate(self::PER_PAGE, ['*'], 'reschedules_page')
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE, 'reschedules_page')) : null,
            'counts' => [
                'reports' => $load->attempt('count_reports', fn () => WorkOrderReport::query()->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)->count()),
                'reschedules' => $load->attempt('count_reschedules', fn () => WorkOrderReschedule::query()->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)->count()),
            ],
            'poskoOptions' => $load->attempt('poskos', fn () => Posko::query()->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(), []),
            'load' => $load,
        ]);
    }
}
