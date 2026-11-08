<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\InspectionFinding;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReschedule;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderReviewService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pusat Review & Validasi: laporan menunggu verifikasi, pengajuan reschedule,
 * permintaan limpahan, dan temuan inspeksi. Keputusan mencatat Admin, waktu, dan alasan.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 15;

    public const CATEGORIES = [
        'reports' => 'Laporan menunggu verifikasi',
        'reschedules' => 'Pengajuan reschedule',
        'escalations' => 'Permintaan limpahan',
        'findings' => 'Temuan inspeksi',
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
        $show = fn (string $category) => in_array($filters['category'], ['', $category], true);
        $page = fn (string $name, Builder $query) => $query->paginate(self::PER_PAGE, ['*'], $name.'_page')->withQueryString()->tap(DataLoader::withinRange(...));

        return view('admin.reviews.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'show' => collect(array_keys(self::CATEGORIES))->mapWithKeys(fn ($c) => [$c => $show($c)])->all(),
            'reports' => $show('reports') ? $load->attempt('reports', fn () => $page('reports', WorkOrderReport::query()
                ->with(['workOrder.posko', 'workOrder.apkukTarget', 'workOrder.locationTarget', 'submittedByTeam', 'personnel', 'media'])
                ->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)
                ->whereHas('workOrder', $constrainWorkOrder)
                ->orderBy('created_at')), DataLoader::emptyPage(self::PER_PAGE, 'reports_page')) : null,
            'reschedules' => $show('reschedules') ? $load->attempt('reschedules', fn () => $page('reschedules', WorkOrderReschedule::query()
                ->with(['workOrder.posko', 'requestedByTeam'])
                ->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)
                ->whereHas('workOrder', $constrainWorkOrder)
                ->orderBy('created_at')), DataLoader::emptyPage(self::PER_PAGE, 'reschedules_page')) : null,
            'escalations' => $show('escalations') ? $load->attempt('escalations', fn () => $page('escalations', WorkEscalation::query()
                ->with(['sourceWorkOrder.posko', 'sourceWorkOrder.penyulang', 'requestedByTeam', 'requestedBy'])
                ->where('status', WorkEscalation::STATUS_MENUNGGU_ACC)
                ->whereHas('sourceWorkOrder', $constrainWorkOrder)
                ->orderBy('created_at')), DataLoader::emptyPage(self::PER_PAGE, 'escalations_page')) : null,
            'findings' => $show('findings') && $filters['team_type'] === '' && $filters['posko_id'] === '' ? $load->attempt('findings', fn () => $page('findings', InspectionFinding::query()
                ->with(['inspection.fieldTeam', 'inspection.penyulang', 'media'])
                ->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)
                ->orderBy('created_at')), DataLoader::emptyPage(self::PER_PAGE, 'findings_page')) : null,
            'counts' => [
                'reports' => $load->attempt('count_reports', fn () => WorkOrderReport::query()->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)->count()),
                'reschedules' => $load->attempt('count_reschedules', fn () => WorkOrderReschedule::query()->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)->count()),
                'escalations' => $load->attempt('count_escalations', fn () => WorkEscalation::query()->where('status', WorkEscalation::STATUS_MENUNGGU_ACC)->count()),
                'findings' => $load->attempt('count_findings', fn () => InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)->count()),
            ],
            'poskoOptions' => $load->attempt('poskos', fn () => Posko::query()->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(), []),
            'teamsByRole' => FieldTeam::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])->groupBy('role')->map(fn ($teams) => $teams->pluck('name', 'id')->all())->all(),
            'rescheduleReasons' => ReferenceCode::query()->where('category', ReferenceCode::CATEGORY_RESCHEDULE_REASON)->pluck('label', 'code')->all(),
            'escalationReasons' => ReferenceCode::query()->where('category', ReferenceCode::CATEGORY_ESCALATION_REASON)->pluck('label', 'code')->all(),
            'findingCodes' => ReferenceCode::query()->where('category', ReferenceCode::CATEGORY_INSPECTION_FINDING)->pluck('label', 'code')->all(),
            'today' => now(ShiftService::TIMEZONE)->toDateString(),
            'load' => $load,
        ]);
    }

    public function approveReport(Request $request, WorkOrderReport $report, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);
        $review->approveReport($report, $request->user(), $data['review_note'] ?? null);

        return back()->with('flash', "Laporan {$report->workOrder->code} disetujui. WO selesai.");
    }

    public function rejectReport(Request $request, WorkOrderReport $report, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $review->rejectReport($report, $request->user(), $data['review_note']);

        return back()->with('flash', "Laporan {$report->workOrder->code} ditolak; WO dikembalikan ke status Ditugaskan.");
    }

    public function approveReschedule(Request $request, WorkOrderReschedule $reschedule, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);
        $review->approveReschedule($reschedule, $request->user(), $data['review_note'] ?? null);

        return back()->with('flash', "Reschedule {$reschedule->workOrder->code} disetujui. WO dijadwalkan ulang.");
    }

    public function rejectReschedule(Request $request, WorkOrderReschedule $reschedule, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $review->rejectReschedule($reschedule, $request->user(), $data['review_note']);

        return back()->with('flash', "Reschedule {$reschedule->workOrder->code} ditolak; WO kembali ke status kerja semula.");
    }

    public function approveEscalation(Request $request, WorkEscalation $escalation, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate([
            'field_team_id' => ['nullable', 'uuid'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $escalation = $review->approveEscalation($escalation, $request->user(), $data, $data['review_note'] ?? null);

        return redirect()->route('admin.work-orders.show', $escalation->child_work_order_id)
            ->with('flash', "Limpahan disetujui. Child WO dibuat; WO sumber {$escalation->sourceWorkOrder->code} berstatus Selesai Diteruskan.");
    }

    public function rejectEscalation(Request $request, WorkEscalation $escalation, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $review->rejectEscalation($escalation, $request->user(), $data['review_note']);

        return back()->with('flash', 'Permintaan limpahan ditolak; tidak ada child WO yang dibuat.');
    }
}
