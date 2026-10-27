<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelWorkOrderRequest;
use App\Http\Requests\Admin\StoreApkukWorkOrderRequest;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\WorkOrder;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderReport;
use App\Services\ApkukCandidateService;
use App\Services\ApkukWorkOrderService;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderExecutionService;
use App\Services\WorkOrderLifecycleService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkOrderController extends Controller
{
    public const STATUS_FILTER_OVERDUE = 'TERLAMBAT';

    private const TARGET_PER_SHIFT = 6;

    private const CANDIDATES_PER_PAGE = 25;

    public function index(Request $request, ShiftService $shiftService): View
    {
        $shift = $shiftService->current();
        $today = $shiftService->today();

        // QueryInput mengabaikan nilai yang ditolak PostgreSQL (UUID/tanggal tidak valid, array).
        $filters = [
            'status' => QueryInput::text($request, 'status', 32),
            'posko_id' => QueryInput::uuid($request, 'posko_id'),
            'shift_code' => QueryInput::text($request, 'shift_code', 1),
            'work_date' => QueryInput::date($request, 'work_date'),
            'q' => QueryInput::text($request, 'q'),
        ];

        $query = WorkOrder::query()->apkuk()->with(['posko', 'penyulang', 'apkukTarget']);

        if ($filters['status'] === self::STATUS_FILTER_OVERDUE) {
            $query->overdue();
        } elseif ($status = WorkOrderStatus::tryFrom($filters['status'])) {
            $query->where('status', $status->value);
        }
        if ($filters['posko_id'] !== '') {
            $query->where('posko_id', $filters['posko_id']);
        }
        if (in_array($filters['shift_code'], $shiftService->codes(), true)) {
            $query->where('shift_code', $filters['shift_code']);
        }
        if ($filters['work_date'] !== '') {
            $query->whereDate('work_date', $filters['work_date']);
        }
        if ($filters['q'] !== '') {
            $term = '%'.mb_strtoupper($filters['q']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where(DB::raw('upper(code)'), 'like', $term)
                    ->orWhereHas('apkukTarget', fn ($t) => $t->where(DB::raw('upper(no_tiang)'), 'like', $term));
            });
        }

        $work_orders = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->tap(DataLoader::withinRange(...));

        $currentShift = fn () => WorkOrder::query()->apkuk()
            ->whereDate('work_date', $today)
            ->where('shift_code', $shift['code'])
            ->where('status', '!=', WorkOrderStatus::Dibatalkan->value);

        $wo_shift_ini = $currentShift()->count();
        $wo_dikerjakan = $currentShift()->where('status', WorkOrderStatus::Dikerjakan->value)->count();
        $wo_selesai = $currentShift()->where('status', WorkOrderStatus::Selesai->value)->count();
        $wo_terlambat = WorkOrder::query()->apkuk()->overdue()->count();

        $poskos = Posko::where('is_active', true)->orderBy('nama_posko')->get();
        $target_per_shift = self::TARGET_PER_SHIFT;
        $target_total = $poskos->count() * $target_per_shift;

        $progressRows = $currentShift()
            ->select('posko_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('posko_id', 'status')
            ->get();
        $posko_progress = $poskos->mapWithKeys(function (Posko $posko) use ($progressRows): array {
            $rows = $progressRows->where('posko_id', $posko->id);
            $done = (int) $rows->where('status', WorkOrderStatus::Selesai)->sum('total');

            return [$posko->id => [
                'total' => (int) $rows->sum('total'),
                'selesai' => $done,
                'aktif' => (int) $rows->filter(fn ($r) => $r->status->isActive())->sum('total'),
            ]];
        });

        $statuses = WorkOrderStatus::cases();
        $shift_options = array_map(fn (string $code) => $shiftService->describe($code), $shiftService->codes());
        $shift_name = $shift['name'];
        $shift_time = $shift['time'];
        $shift_index = $shift['index'];

        return view('admin.work-orders.index', compact(
            'wo_shift_ini',
            'wo_dikerjakan',
            'wo_selesai',
            'wo_terlambat',
            'poskos',
            'posko_progress',
            'target_per_shift',
            'target_total',
            'work_orders',
            'filters',
            'statuses',
            'shift_options',
            'shift_name',
            'shift_time',
            'shift_index'
        ));
    }

    public function create(Request $request, ApkukCandidateService $candidateService, ShiftService $shiftService): View
    {
        $filters = [
            'penyulang_id' => QueryInput::uuid($request, 'penyulang_id'),
            'q' => QueryInput::text($request, 'q'),
            'show' => $request->query('show') === 'all' ? 'all' : 'eligible',
        ];

        $all = $candidateService->evaluate();
        $summary = $candidateService->summary($all);
        $filtered = $candidateService->filter(
            $all,
            $filters['penyulang_id'] !== '' ? $filters['penyulang_id'] : null,
            $filters['q'],
            $filters['show'] === 'eligible',
        );

        $page = LengthAwarePaginator::resolveCurrentPage();
        $candidates = new LengthAwarePaginator(
            $filtered->forPage($page, self::CANDIDATES_PER_PAGE)->values(),
            $filtered->count(),
            self::CANDIDATES_PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $selectedId = (int) old('apkuk_id', $request->query('apkuk_id'));
        $selected = $selectedId > 0 ? $all->get($selectedId) : null;

        $poskos = Posko::where('is_active', true)->orderBy('nama_posko')->get();
        $penyulangs = Penyulang::where('is_active', true)->orderBy('kode_penyulang')->get();
        $shift_options = array_map(fn (string $code) => $shiftService->describe($code), $shiftService->codes());
        $default_work_date = $shiftService->today();
        $default_shift = $shiftService->current()['code'];
        $issue_labels = ApkukCandidateService::LABELS;

        return view('admin.work-orders.create', compact(
            'filters',
            'summary',
            'candidates',
            'selected',
            'poskos',
            'penyulangs',
            'shift_options',
            'default_work_date',
            'default_shift',
            'issue_labels'
        ));
    }

    public function store(StoreApkukWorkOrderRequest $request, ApkukWorkOrderService $service): RedirectResponse
    {
        $workOrder = $service->createManual($request->user(), $request->validated());

        return redirect()
            ->route('admin.work-orders.show', $workOrder)
            ->with('flash', "WO APKUK {$workOrder->code} berhasil dibuat.");
    }

    public function show(WorkOrder $workOrder, ShiftService $shiftService): View
    {
        $workOrder->load([
            'posko',
            'penyulang',
            'fieldTeam',
            'apkukTarget',
            'locationTarget',
            'parent',
            'children',
            'createdBy',
            'cancelledBy',
            'statusLogs.actor',
            'reschedules.reviewedBy',
            'reschedules.requestedByTeam',
            'reports.personnel',
            'reports.media',
            'reports.verifiedBy',
            'reports.submittedByTeam',
            'escalations.reviewedBy',
            'escalations.childWorkOrder',
            'sourceEscalation.sourceWorkOrder',
            'issues.resolvedBy',
            'media',
        ]);

        $shift = $workOrder->shift_code ? $shiftService->describe($workOrder->shift_code) : null;
        $teams = FieldTeam::query()->where('role', $workOrder->team_type)->where('is_active', true)
            ->when($workOrder->team_type === WorkOrder::TEAM_POSKO && $workOrder->posko_id, fn ($q) => $q->where('posko_id', $workOrder->posko_id))
            ->with(['personnel' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return view('admin.work-orders.show', [
            'wo' => $workOrder,
            'shift' => $shift,
            'teams' => $teams,
            'reportTypes' => array_intersect_key(WorkOrderReport::TYPE_LABELS, array_flip(WorkOrderExecutionService::reportTypesFor($workOrder))),
            'escalationReasons' => ReferenceCode::options(ReferenceCode::CATEGORY_ESCALATION_REASON, $workOrder->team_type),
            'escalationTargets' => collect([WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW])
                ->reject(fn ($team) => $team === $workOrder->team_type)
                ->flatMap(fn ($team) => collect(WorkOrder::WORK_TYPES_BY_TEAM[$team])->mapWithKeys(fn ($type) => [$team.'|'.$type => $team.' — '.WorkOrder::WORK_TYPE_LABELS[$type]]))
                ->all(),
            'issueTypes' => WorkOrderIssue::TYPE_LABELS,
            'nowWib' => now(ShiftService::TIMEZONE)->format('Y-m-d\TH:i'),
        ]);
    }

    public function cancel(CancelWorkOrderRequest $request, WorkOrder $workOrder, WorkOrderLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->cancel($workOrder, $request->user(), trim($request->validated('cancel_reason')));

        return redirect()
            ->route('admin.work-orders.show', $workOrder)
            ->with('flash', 'WO dibatalkan dan tercatat di riwayat audit. Lokasi dapat dibuatkan WO baru.');
    }
}
