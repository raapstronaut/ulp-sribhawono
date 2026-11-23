<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Row\RowInspectionPlanService;
use App\Services\Row\RowPlanningReadOnlyPreview;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

/** Admin menyusun klasifikasi ROW tanpa membuat WO. */
final class RowPlanController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->isAllowedEnvironment(), 404);
        $ready = Schema::hasTable('row_inspection_plans') && Schema::hasTable('row_inspection_plan_logs');
        $date = QueryInput::date($request, 'work_date') ?: now('Asia/Jakarta')->toDateString();
        if (! $ready) {
            return view('admin.row.plan', [
                'ready' => false, 'date' => $date, 'teams' => collect(),
                'findings' => collect(), 'plans' => collect(), 'quota' => [],
            ]);
        }
        $teams = FieldTeam::query()->where('role', WorkOrder::TEAM_ROW)->where('is_active', true)
            ->orderBy('code')->get();
        $findings = InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->where('suggested_team_type', WorkOrder::TEAM_ROW)
            ->whereNull('follow_up_work_order_id')
            ->with('inspection.penyulang')
            ->orderBy('created_at')->orderBy('id')
            ->limit(100)->get();
        $plans = DB::table('row_inspection_plans')->whereIn('inspection_finding_id', $findings->pluck('id'))
            ->get()->keyBy('inspection_finding_id');
        $quota = [];
        foreach ($teams as $team) {
            $existing = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
                ->where('field_team_id', $team->getKey())->where('source', WorkOrder::SOURCE_SYSTEM)
                ->whereDate('work_date', $date)->count();
            $manual = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
                ->where('field_team_id', $team->getKey())->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)
                ->whereDate('work_date', $date)->count();
            $prepared = DB::table('row_inspection_plans as p')
                ->join('inspection_findings as f', 'f.id', '=', 'p.inspection_finding_id')
                ->where('p.field_team_id', $team->getKey())
                ->where('f.validation_status', InspectionFinding::VALIDATION_DISETUJUI)
                ->where('f.suggested_team_type', WorkOrder::TEAM_ROW)
                ->whereNull('f.follow_up_work_order_id')->count();
            $quota[] = [
                'code' => $team->code,
                'system' => $existing,
                'manual' => $manual,
                'prepared' => $prepared,
                'target' => 10,
                'remaining_system_slots' => max(0, 10 - $existing),
            ];
        }
        return view('admin.row.plan', compact('ready', 'date', 'teams', 'findings', 'plans', 'quota'));
    }

    /** Only a GET and only a database-backed read-only preview. No WO writes. */
    public function preview(Request $request, RowPlanningReadOnlyPreview $reader): View
    {
        abort_unless($this->isAllowedEnvironment(), 404);
        abort_unless(Schema::hasTable('row_inspection_plans') && Schema::hasTable('row_inspection_plan_logs'), 503);
        $validated = $request->validate(['work_date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $validated['work_date'] ?? now('Asia/Jakarta')->toDateString();
        $report = $reader->preview($date);

        return view('admin.row.preview', compact('date', 'report'));
    }

    public function store(Request $request, InspectionFinding $finding, RowInspectionPlanService $service): RedirectResponse
    {
        abort_unless($this->isAllowedEnvironment(), 404);
        abort_unless(Schema::hasTable('row_inspection_plans') && Schema::hasTable('row_inspection_plan_logs'), 503);
        $validated = $request->validate([
            'field_team_id' => ['required', 'uuid'],
            'work_type' => ['required', Rule::in(WorkOrder::ROW_WORK_TYPES)],
            'work_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $changed = $service->save($finding, $request->user(), $validated['field_team_id'], $validated['work_type']);
        return redirect()->route('admin.row.plan', [
            'work_date' => $validated['work_date'] ?? now('Asia/Jakarta')->toDateString(),
        ])->with('status', $changed ? 'Rencana ROW dicatat. Belum ada WO yang dibuat.' : 'Rencana ROW sudah sama; tidak ada perubahan.');
    }

    private function isAllowedEnvironment(): bool
    {
        $env = (string) app()->environment();
        $connection = (string) config('database.default');
        $db = (array) config("database.connections.{$connection}", []);
        if (filled($db['url'] ?? null)) {
            return false;
        }
        if ($env === 'testing') {
            return $connection === 'sqlite' && ($db['driver'] ?? null) === 'sqlite'
                && ($db['database'] ?? null) === ':memory:';
        }
        return $env === 'local' && $connection === 'pgsql' && ($db['driver'] ?? null) === 'pgsql'
            && in_array((string) ($db['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            && (string) ($db['port'] ?? '') === '5432'
            && ($db['database'] ?? null) === 'ulp_sribhawono_dev';
    }
}
