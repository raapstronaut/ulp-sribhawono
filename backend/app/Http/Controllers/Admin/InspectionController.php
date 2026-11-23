<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\WorkOrder;
use App\Services\Inspection\ApprovedFindingWorkOrderService;
use App\Services\Inspection\InspectionApkukWorkOrderService;
use App\Services\Inspection\InspectionService;
use App\Services\ShiftService;
use App\Services\Work\MediaStore;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Modul Inspeksi: daftar kunjungan, input Admin, detail, dan validasi temuan.
 * Ranking/rotasi area (formula prioritas) dan rekomendasi tim otomatis masih TBD.
 */
class InspectionController extends Controller
{
    private const PER_PAGE = 20;

    public const RESULT_FILTERS = ['finding' => 'Ada temuan', 'none' => 'Tidak ada temuan', 'pending' => 'Ada temuan menunggu validasi'];

    public function index(Request $request, DataLoader $load, ShiftService $shifts): View
    {
        $filters = [
            'field_team_id' => QueryInput::uuid($request, 'field_team_id'),
            'penyulang_id' => QueryInput::uuid($request, 'penyulang_id'),
            'result' => QueryInput::choice($request, 'result', array_keys(self::RESULT_FILTERS)),
            'date_from' => QueryInput::date($request, 'date_from'),
            'date_to' => QueryInput::date($request, 'date_to'),
            'q' => QueryInput::text($request, 'q'),
        ];
        $dayStart = fn (string $date) => CarbonImmutable::parse($date, ShiftService::TIMEZONE)->startOfDay()->utc();
        $todayStart = $dayStart($shifts->today());

        return view('admin.inspeksi.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($v) => $v !== ''),
            'inspections' => $load->attempt('inspections', fn () => Inspection::query()
                ->with(['fieldTeam', 'penyulang'])
                ->withCount(['findings', 'findings as pending_count' => fn ($q) => $q->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)])
                ->when($filters['field_team_id'] !== '', fn ($q) => $q->where('field_team_id', $filters['field_team_id']))
                ->when($filters['penyulang_id'] !== '', fn ($q) => $q->where('penyulang_id', $filters['penyulang_id']))
                ->when($filters['result'] === 'finding', fn ($q) => $q->where('has_finding', true))
                ->when($filters['result'] === 'none', fn ($q) => $q->where('has_finding', false))
                ->when($filters['result'] === 'pending', fn ($q) => $q->whereHas('findings', fn ($f) => $f->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)))
                ->when($filters['date_from'] !== '', fn ($q) => $q->where('visited_at', '>=', $dayStart($filters['date_from'])))
                ->when($filters['date_to'] !== '', fn ($q) => $q->where('visited_at', '<', $dayStart($filters['date_to'])->addDay()))
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(code)'), 'like', '%'.mb_strtoupper($filters['q']).'%')
                    ->orWhere(DB::raw('upper(section_name)'), 'like', '%'.mb_strtoupper($filters['q']).'%')))
                ->orderByDesc('visited_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE)),
            'summary' => $load->attempt('summary', fn () => [
                'visits_today' => Inspection::query()->where('visited_at', '>=', $todayStart)->count(),
                'real_findings_today' => InspectionFinding::query()->whereHas('inspection', fn ($q) => $q->where('visited_at', '>=', $todayStart))->count(),
                'no_finding_today' => Inspection::query()->where('visited_at', '>=', $todayStart)->where('has_finding', false)->count(),
                'pending' => InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)->count(),
                'approved' => InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)->count(),
            ]),
            'teams' => $load->attempt('teams', fn () => FieldTeam::query()->where('role', InspectionService::TEAM_ROLE)
                ->withCount(['personnel' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get(), collect()),
            'teamOptions' => FieldTeam::query()->where('role', InspectionService::TEAM_ROLE)->orderBy('name')->pluck('name', 'id')->all(),
            'penyulangOptions' => Penyulang::query()->where('is_active', true)->orderBy('kode_penyulang')->pluck('nama_penyulang', 'id')->all(),
            'load' => $load,
        ]);
    }

    public function create(): View
    {
        $teams = FieldTeam::query()->where('role', InspectionService::TEAM_ROLE)->where('is_active', true)
            ->with(['personnel' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->orderBy('name')->get();

        return view('admin.inspeksi.create', [
            'teams' => $teams,
            'penyulangOptions' => Penyulang::query()->where('is_active', true)->orderBy('kode_penyulang')->pluck('nama_penyulang', 'id')->all(),
            'findingCodes' => ReferenceCode::options(ReferenceCode::CATEGORY_INSPECTION_FINDING, InspectionService::TEAM_ROLE),
            'teamTypeOptions' => [WorkOrder::TEAM_POSKO => 'POSKO', WorkOrder::TEAM_HARSUS => 'HARSUS', WorkOrder::TEAM_ROW => 'ROW'],
            'nowWib' => now(ShiftService::TIMEZONE)->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(Request $request, InspectionService $service): RedirectResponse
    {
        $image = ['file', 'mimes:'.implode(',', MediaStore::MIMES), 'max:'.MediaStore::MAX_KILOBYTES];
        $data = $request->validate([
            'field_team_id' => ['required', 'uuid'],
            'penyulang_id' => ['required', 'uuid'],
            'section_name' => ['nullable', 'string', 'max:255'],
            'visited_at' => ['required', 'date'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'personnel_ids' => ['nullable', 'array', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'personnel_names' => ['nullable', 'string', 'max:500'],
            'findings' => ['nullable', 'array', 'max:10'],
            'findings.*.finding_code' => ['nullable', 'string', 'max:80'],
            'findings.*.description' => ['nullable', 'string', 'max:2000'],
            'findings.*.keypoint_id' => ['nullable', 'integer'],
            'findings.*.asset_label' => ['nullable', 'string', 'max:255'],
            'findings.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'findings.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'findings.*.suggested_team_type' => ['nullable', 'string', 'max:24'],
            'findings.*.apkuk_no_tiang' => ['nullable', 'string', 'max:191'],
            'findings.*.apkuk_target_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'findings.*.photos' => ['nullable', 'array', 'max:5'],
            'findings.*.photos.*' => $image,
        ]);

        // Baris temuan kosong di form diabaikan; baris yang diisi sebagian tetap divalidasi service.
        $findings = [];
        foreach ($data['findings'] ?? [] as $index => $finding) {
            $finding['photos'] = $request->file("findings.{$index}.photos", []);
            if (collect($finding)->except('photos')->filter(fn ($v) => filled($v))->isEmpty() && $finding['photos'] === []) {
                continue;
            }
            $findings[] = $finding;
        }
        $data['findings'] = $findings;
        $data['personnel_names'] = array_map('trim', explode(',', (string) ($data['personnel_names'] ?? '')));

        $inspection = $service->record($data, $request->user(), null, Inspection::SOURCE_ADMIN, requirePhotos: false);

        return redirect()->route('admin.inspeksi.show', $inspection)->with('flash', "Inspeksi {$inspection->code} tersimpan".($inspection->has_finding ? '; temuan menunggu validasi.' : ' sebagai kunjungan tanpa temuan.'));
    }

    public function show(Inspection $inspection): View
    {
        $inspection->load(['fieldTeam', 'penyulang', 'personnel', 'submittedBy', 'findings.media', 'findings.keypoint', 'findings.validatedBy', 'findings.followUpWorkOrder']);

        return view('admin.inspeksi.show', [
            'inspection' => $inspection,
            'findingCodes' => ReferenceCode::query()->where('category', ReferenceCode::CATEGORY_INSPECTION_FINDING)->pluck('label', 'code')->all(),
            'followUpTypes' => [
                WorkOrder::TEAM_HARSUS.'|'.WorkOrder::TYPE_PEKERJAAN_KHUSUS => 'HARSUS — Pekerjaan khusus',
                WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_RABAS => 'ROW — Rabas',
                WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_PANGKAS => 'ROW — Pangkas',
                WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_TEBANG => 'ROW — Tebang',
            ],
            'teamsByRole' => FieldTeam::query()->where('is_active', true)->whereIn('role', [WorkOrder::TEAM_POSKO, WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW])->orderBy('name')->get(['id', 'name', 'role'])->groupBy('role')->map(fn ($t) => $t->pluck('name', 'id')->all())->all(),
            'poskoOptions' => Posko::query()->where('is_active', true)->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(),
            'shiftOptions' => app(ShiftService::class)->codes(),
            'today' => now(ShiftService::TIMEZONE)->toDateString(),
        ]);
    }

    public function approveFinding(Request $request, InspectionFinding $finding, InspectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
            'follow_up' => ['nullable', 'string', 'max:80'],
            'field_team_id' => ['nullable', 'uuid'],
            'work_date' => ['nullable', 'required_with:follow_up', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'required_with:follow_up', 'date_format:Y-m-d', 'after_or_equal:work_date'],
        ]);

        $followUp = null;
        if (filled($data['follow_up'] ?? null)) {
            [$teamType, $workType] = array_pad(explode('|', $data['follow_up'], 2), 2, '');
            $followUp = ['team_type' => $teamType, 'work_type' => $workType] + $data;
        }
        $finding = $service->approveFinding($finding, $request->user(), $data['review_note'] ?? null, $followUp);

        return redirect()->route('admin.inspeksi.show', $finding->inspection_id)->with('flash', $finding->follow_up_work_order_id
            ? 'Temuan disetujui dan WO tindak lanjut dibuat.'
            : 'Temuan disetujui.');
    }

    /**
     * Admin boleh membuat WO HARSUS/ROW setelah ACC sebelumnya dilakukan tanpa WO.
     * Tidak dipakai untuk generator dan tidak menyentuh jalur POSKO legacy.
     */
    public function createApprovedFindingWorkOrder(Request $request, InspectionFinding $finding, ApprovedFindingWorkOrderService $service): RedirectResponse
    {
        $allowed = [
            WorkOrder::TEAM_HARSUS.'|'.WorkOrder::TYPE_PEKERJAAN_KHUSUS,
            WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_RABAS,
            WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_PANGKAS,
            WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_TEBANG,
        ];
        $data = $request->validate([
            'follow_up' => ['required', 'string', \Illuminate\Validation\Rule::in($allowed)],
            'field_team_id' => ['nullable', 'uuid'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$teamType, $workType] = explode('|', $data['follow_up'], 2);
        $workOrder = $service->create($finding, $request->user(), $data + [
            'team_type' => $teamType,
            'work_type' => $workType,
        ]);

        return redirect()->route('admin.inspeksi.show', $finding->inspection_id)
            ->with('flash', "WO {$workOrder->code} dibuat dari temuan inspeksi yang telah disetujui.");
    }

    /**
     * Admin makes a manual APKUK WO from an approved INSPEKSI finding.
     * Generator SYSTEM is intentionally untouched.
     */
    public function createApprovedPoskoWorkOrder(Request $request, InspectionFinding $finding, InspectionApkukWorkOrderService $service, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate([
            'posko_id' => ['required', 'uuid'],
            'field_team_id' => ['nullable', 'uuid'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'shift_code' => ['required', \Illuminate\Validation\Rule::in($shifts->codes())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $wo = $service->createManual($finding, $request->user(), $data);

        return redirect()->route('admin.inspeksi.show', $finding->inspection_id)
            ->with('flash', "WO POSKO {$wo->code} dibuat dari temuan INSPEKSI yang disetujui.");
    }

    public function rejectFinding(Request $request, InspectionFinding $finding, InspectionService $service): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $service->rejectFinding($finding, $request->user(), $data['review_note']);

        return redirect()->route('admin.inspeksi.show', $finding->inspection_id)->with('flash', 'Temuan ditolak dan tidak menjadi dasar tindakan.');
    }
}
