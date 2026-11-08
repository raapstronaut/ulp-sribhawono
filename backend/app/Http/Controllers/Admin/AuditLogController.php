<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WorkOrderStatusLog;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Audit log & histori (baca saja) dari jejak append-only work_order_status_logs.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public const ACTIONS = WorkOrderStatusLog::ACTION_LABELS;

    public const TEAM_TYPES = ['POSKO' => 'POSKO', 'HARSUS' => 'HARSUS', 'ROW' => 'ROW'];

    public function index(Request $request, ShiftService $shifts, DataLoader $load): View
    {
        $filters = [
            'action' => QueryInput::text($request, 'action', 40),
            'team_type' => QueryInput::choice($request, 'team_type', array_keys(self::TEAM_TYPES)),
            'date_from' => QueryInput::date($request, 'date_from'),
            'date_to' => QueryInput::date($request, 'date_to'),
            'q' => QueryInput::text($request, 'q'),
        ];

        $logs = $load->attempt('logs', fn () => WorkOrderStatusLog::query()
            ->with(['workOrder:id,code,team_type,work_type', 'actor:id,username,name', 'actorFieldTeam:id,name'])
            ->when($filters['action'] !== '', fn ($query) => $query->where('action', $filters['action']))
            ->when($filters['team_type'] !== '', fn ($query) => $query->whereHas('workOrder', fn ($wo) => $wo->where('team_type', $filters['team_type'])))
            ->when($filters['q'] !== '', fn ($query) => $query->whereHas('workOrder', fn ($wo) => $wo->where(DB::raw('upper(code)'), 'like', '%'.mb_strtoupper($filters['q']).'%')))
            ->when($filters['date_from'] !== '', fn ($query) => $query->where('created_at', '>=', $this->dayStart($filters['date_from'])))
            ->when($filters['date_to'] !== '', fn ($query) => $query->where('created_at', '<', $this->dayStart($filters['date_to'])->addDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));

        $todayStart = $this->dayStart($shifts->today());

        return view('admin.audit-logs.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'logs' => $logs,
            'summary' => $load->attempt('summary', fn () => [
                'total' => WorkOrderStatusLog::query()->count(),
                'today' => WorkOrderStatusLog::query()->where('created_at', '>=', $todayStart)->count(),
                'cancelled' => WorkOrderStatusLog::query()->where('action', WorkOrderStatusLog::ACTION_CANCELLED)->count(),
            ]),
            'actionOptions' => $load->attempt('actions', fn () => DB::table('work_order_status_logs')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->mapWithKeys(fn (string $action) => [$action => self::ACTIONS[$action] ?? $action])
                ->union(self::ACTIONS)
                ->all(), self::ACTIONS),
            'load' => $load,
        ]);
    }

    public function show(WorkOrderStatusLog $auditLog): View
    {
        $auditLog->load(['workOrder', 'actor:id,username,name', 'actorFieldTeam:id,name,code']);

        return view('admin.audit-logs.show', [
            'log' => $auditLog,
            'actionLabel' => self::ACTIONS[$auditLog->action] ?? $auditLog->action,
            'payloadJson' => $auditLog->payload === null
                ? null
                : json_encode($auditLog->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Audit umum: master data, akun & hak akses, keputusan limpahan/temuan/kendala.
     */
    public function general(Request $request, DataLoader $load): View
    {
        $filters = [
            'entity_type' => QueryInput::choice($request, 'entity_type', array_keys(AuditLog::ENTITY_LABELS)),
            'action' => QueryInput::choice($request, 'action', array_keys(AuditLog::ACTION_LABELS)),
            'date_from' => QueryInput::date($request, 'date_from'),
            'date_to' => QueryInput::date($request, 'date_to'),
            'q' => QueryInput::text($request, 'q'),
        ];

        return view('admin.audit-logs.general', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'logs' => $load->attempt('logs', fn () => AuditLog::query()
                ->with(['actor:id,username,name', 'actorFieldTeam:id,name'])
                ->when($filters['entity_type'] !== '', fn ($q) => $q->where('entity_type', $filters['entity_type']))
                ->when($filters['action'] !== '', fn ($q) => $q->where('action', $filters['action']))
                ->when($filters['q'] !== '', fn ($q) => $q->where(DB::raw('upper(entity_label)'), 'like', '%'.mb_strtoupper($filters['q']).'%'))
                ->when($filters['date_from'] !== '', fn ($q) => $q->where('created_at', '>=', $this->dayStart($filters['date_from'])))
                ->when($filters['date_to'] !== '', fn ($q) => $q->where('created_at', '<', $this->dayStart($filters['date_to'])->addDay()))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE)),
            'load' => $load,
        ]);
    }

    public function showGeneral(AuditLog $auditLog): View
    {
        $auditLog->load(['actor:id,username,name', 'actorFieldTeam:id,name,code']);
        $json = fn (?array $value) => $value === null ? null : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('admin.audit-logs.general-show', [
            'log' => $auditLog,
            'beforeJson' => $json($auditLog->before),
            'afterJson' => $json($auditLog->after),
        ]);
    }

    /**
     * Awal hari WIB dalam UTC, karena created_at disimpan dalam zona aplikasi (UTC).
     */
    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, ShiftService::TIMEZONE)->startOfDay()->utc();
    }
}
