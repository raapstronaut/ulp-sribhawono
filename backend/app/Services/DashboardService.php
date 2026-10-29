<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\ApkukSyncRun;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReschedule;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\ApkukSourceState;
use App\Support\PenyulangName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data Dashboard Admin dari database operasional. Tidak ada angka contoh: bila tabel
 * kosong, nilai 0 / daftar kosong ditampilkan apa adanya.
 */
class DashboardService
{
    public const APKUK_TARGET_PER_POSKO_SHIFT = 6;

    public const PERIODS = ['today' => 'Hari ini', '7d' => '7 hari', '30d' => '30 hari'];

    // Pengelompokan status untuk ringkasan operasional.
    public const STATUS_GROUPS = [
        'belum' => [WorkOrderStatus::Ditugaskan, WorkOrderStatus::DijadwalkanUlang],
        'dikerjakan' => [WorkOrderStatus::Dikerjakan],
        'menunggu' => [WorkOrderStatus::MenungguVerifikasi, WorkOrderStatus::RescheduleDiajukan],
        'selesai' => [WorkOrderStatus::Selesai, WorkOrderStatus::SelesaiDiteruskan],
        'batal' => [WorkOrderStatus::Dibatalkan],
    ];

    public const GROUP_LABELS = [
        'belum' => 'Belum dikerjakan',
        'dikerjakan' => 'Sedang dikerjakan',
        'menunggu' => 'Menunggu keputusan',
        'selesai' => 'Selesai',
        'batal' => 'Dibatalkan',
    ];

    public function __construct(private readonly ShiftService $shiftService) {}

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(string $period = 'today'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'today';
        $now = CarbonImmutable::now(ShiftService::TIMEZONE);
        $shift = $this->shiftService->current($now) + ['operational_date' => $this->shiftService->operationalDate($now)];
        $from = match ($period) {
            '7d' => $now->subDays(6)->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            default => $now->startOfDay(),
        };
        $poskos = Posko::query()->where('is_active', true)->orderBy('nama_posko')->get();

        return [
            'context' => [
                'updated_at' => $now->toIso8601String(),
                'updated_at_label' => $now->format('d-m-Y H:i').' WIB',
                'period' => $period,
                'period_label' => self::PERIODS[$period],
                'period_from' => $from->toDateString(),
                'active_poskos' => $poskos->count(),
                'active_penyulangs' => Penyulang::query()->where('is_active', true)->count(),
            ],
            'shiftData' => $shift,
            'kpis' => $this->kpis($from, $now),
            'statusGroups' => $this->statusGroups(),
            'teamSummaries' => $this->teamSummaries($from),
            'poskoStatuses' => $this->poskoStatuses($poskos, $shift, $now),
            'byPenyulang' => $this->byPenyulang(),
            'trend' => $this->trend($now),
            'apkuk' => $this->apkuk($from),
            'gangguan' => $this->gangguan($now),
            'inspection' => $this->inspection($now),
            'pending' => $this->pending(),
            'recentActivities' => $this->recentActivities(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function kpis(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $values = fn (array $statuses) => array_map(fn (WorkOrderStatus $s) => $s->value, $statuses);

        return [
            'total' => WorkOrder::query()->count(),
            'active' => WorkOrder::query()->active()->count(),
            'belum' => WorkOrder::query()->whereIn('status', $values(self::STATUS_GROUPS['belum']))->count(),
            'dikerjakan' => WorkOrder::query()->whereIn('status', $values(self::STATUS_GROUPS['dikerjakan']))->count(),
            'menunggu' => WorkOrder::query()->whereIn('status', $values(self::STATUS_GROUPS['menunggu']))->count(),
            'selesai_periode' => WorkOrder::query()->whereIn('status', $values(self::STATUS_GROUPS['selesai']))
                ->whereBetween('completed_at', [$from->utc(), $now->utc()])->count(),
            'dibuat_periode' => WorkOrder::query()->whereBetween('created_at', [$from->utc(), $now->utc()])->count(),
            'terlambat' => WorkOrder::query()->overdue()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusGroups(): array
    {
        $counts = WorkOrder::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $result = [];
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            $result[$group] = (int) collect($statuses)->sum(fn (WorkOrderStatus $s) => $counts[$s->value] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamSummaries(CarbonImmutable $from): array
    {
        $rows = WorkOrder::query()->select('team_type', 'status', DB::raw('count(*) as total'))->groupBy('team_type', 'status')->toBase()->get();
        $overdue = WorkOrder::query()->overdue()->select('team_type', DB::raw('count(*) as total'))->groupBy('team_type')->pluck('total', 'team_type');
        $done = WorkOrder::query()->where('status', WorkOrderStatus::Selesai->value)->where('completed_at', '>=', $from->utc())
            ->select('team_type', DB::raw('count(*) as total'))->groupBy('team_type')->pluck('total', 'team_type');

        $summaries = [];
        foreach (WorkOrder::TEAM_LABELS as $team => $label) {
            $teamRows = $rows->where('team_type', $team);
            $summaries[] = [
                'team' => $team,
                'label' => $label,
                'work_types' => array_map(fn ($t) => WorkOrder::WORK_TYPE_LABELS[$t], WorkOrder::WORK_TYPES_BY_TEAM[$team]),
                'active' => (int) $teamRows->filter(fn ($r) => WorkOrderStatus::from($r->status)->isActive())->sum('total'),
                'waiting' => (int) $teamRows->whereIn('status', [WorkOrderStatus::MenungguVerifikasi->value, WorkOrderStatus::RescheduleDiajukan->value])->sum('total'),
                'overdue' => (int) ($overdue[$team] ?? 0),
                'completed_period' => (int) ($done[$team] ?? 0),
                'route' => match ($team) {
                    WorkOrder::TEAM_HARSUS => 'admin.harsus.index',
                    WorkOrder::TEAM_ROW => 'admin.row.index',
                    default => 'admin.work-orders.index',
                },
            ];
        }

        return $summaries;
    }

    /**
     * Progres WO APKUK per Posko pada shift berjalan terhadap target 6 WO per shift.
     *
     * @return array<int, array<string, mixed>>
     */
    private function poskoStatuses(Collection $poskos, array $shift, CarbonImmutable $now): array
    {
        $rows = WorkOrder::query()->apkuk()
            ->whereDate('work_date', $shift['operational_date'])
            ->where('shift_code', $shift['code'])
            ->where('status', '!=', WorkOrderStatus::Dibatalkan->value)
            ->select('posko_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('posko_id', 'status')
            ->toBase()
            ->get();
        $overdue = WorkOrder::query()->overdue()->whereNotNull('posko_id')->select('posko_id', DB::raw('count(*) as total'))->groupBy('posko_id')->pluck('total', 'posko_id');
        $teams = DB::table('field_teams')->where('role', 'POSKO')->whereNotNull('posko_id')->select('posko_id', DB::raw('count(*) as total'))->groupBy('posko_id')->pluck('total', 'posko_id');

        return $poskos->map(function (Posko $posko) use ($rows, $overdue, $teams): array {
            $posRows = $rows->where('posko_id', $posko->id);

            return [
                'id' => $posko->id,
                'name' => $posko->nama_posko,
                'apkuk_target' => self::APKUK_TARGET_PER_POSKO_SHIFT,
                'apkuk_total' => (int) $posRows->sum('total'),
                'apkuk_completed' => (int) $posRows->where('status', WorkOrderStatus::Selesai->value)->sum('total'),
                'apkuk_active' => (int) $posRows->filter(fn ($r) => WorkOrderStatus::from($r->status)->isActive())->sum('total'),
                'overdue' => (int) ($overdue[$posko->id] ?? 0),
                'teams' => (int) ($teams[$posko->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array{name: string, active: int, overdue: int}>
     */
    private function byPenyulang(): array
    {
        $active = WorkOrder::query()->active()->whereNotNull('penyulang_id')->select('penyulang_id', DB::raw('count(*) as total'))->groupBy('penyulang_id')->pluck('total', 'penyulang_id');
        $overdue = WorkOrder::query()->overdue()->whereNotNull('penyulang_id')->select('penyulang_id', DB::raw('count(*) as total'))->groupBy('penyulang_id')->pluck('total', 'penyulang_id');

        return Penyulang::query()->where('is_active', true)->orderBy('kode_penyulang')->get()
            ->map(fn (Penyulang $p) => ['name' => $p->nama_penyulang, 'active' => (int) ($active[$p->id] ?? 0), 'overdue' => (int) ($overdue[$p->id] ?? 0)])
            ->sortByDesc('active')
            ->values()
            ->all();
    }

    /**
     * WO dibuat vs diselesaikan per hari WIB, 14 hari terakhir.
     *
     * @return array<int, array{date: string, label: string, created: int, completed: int}>
     */
    private function trend(CarbonImmutable $now): array
    {
        $start = $now->subDays(13)->startOfDay();
        $created = WorkOrder::query()->where('created_at', '>=', $start->utc())->get(['created_at'])
            ->countBy(fn ($wo) => $wo->created_at->copy()->timezone(ShiftService::TIMEZONE)->toDateString());
        $completed = WorkOrder::query()->whereNotNull('completed_at')->where('completed_at', '>=', $start->utc())->get(['completed_at'])
            ->countBy(fn ($wo) => $wo->completed_at->copy()->timezone(ShiftService::TIMEZONE)->toDateString());

        $days = [];
        for ($day = $start; $day->lte($now); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'label' => $day->format('d/m'), 'created' => (int) ($created[$key] ?? 0), 'completed' => (int) ($completed[$key] ?? 0)];
        }

        return $days;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function apkuk(CarbonImmutable $from): array
    {
        return [
            // Pending (tidak ada di snapshot SPS BOS terakhir) tidak dihitung sebagai laporan aktif.
            'active_reports' => ApkukSourceState::active(DB::table('apkuks'))->count(),
            'pending_reports' => ApkukSourceState::pending(DB::table('apkuks'))->count(),
            'history_reports' => ApkukSourceState::history(DB::table('apkuks'))->count(),
            'last_synced_at' => DB::table('apkuks')->max('last_synced_at'),
            'last_run_status' => ApkukSyncRun::query()->latest('id')->first()?->statusLabel(),
            'wo_active' => WorkOrder::query()->apkuk()->active()->count(),
            'installed_period' => (int) DB::table('work_order_apkuk_targets as t')
                ->join('work_orders as w', 'w.id', '=', 't.work_order_id')
                ->where('w.completed_at', '>=', $from->utc())
                ->sum('t.installed_quantity'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gangguan(CarbonImmutable $now): array
    {
        $since30 = $now->subDays(29)->toDateString();

        return [
            'last_7_days' => DB::table('gangguans')->where('tanggal_kejadian', '>=', $now->subDays(6)->toDateString())->count(),
            'last_30_days' => DB::table('gangguans')->where('tanggal_kejadian', '>=', $since30)->count(),
            'top_penyulang' => DB::table('gangguans')->where('tanggal_kejadian', '>=', $since30)->whereNotNull('penyulang')
                ->select(DB::raw(PenyulangName::sqlCanonical('penyulang').' as penyulang'), DB::raw('count(*) as total'))
                ->groupBy(DB::raw(PenyulangName::sqlCanonical('penyulang')))->orderByDesc('total')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->penyulang, 'total' => (int) $r->total])->all(),
            'last_synced_at' => DB::table('gangguans')->max('last_synced_at'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function inspection(CarbonImmutable $now): array
    {
        $today = $now->startOfDay()->utc();

        return [
            'visits_today' => Inspection::query()->where('visited_at', '>=', $today)->count(),
            'findings_today' => InspectionFinding::query()->whereHas('inspection', fn ($q) => $q->where('visited_at', '>=', $today))->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pending(): array
    {
        return [
            'reports' => WorkOrderReport::query()->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)->count(),
            'reschedules' => WorkOrderReschedule::query()->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)->count(),
            'escalations' => WorkEscalation::query()->where('status', WorkEscalation::STATUS_MENUNGGU_ACC)->count(),
            'findings' => InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)->count(),
            'issues' => WorkOrderIssue::query()->where('status', WorkOrderIssue::STATUS_TERBUKA)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivities(): array
    {
        return WorkOrderStatusLog::query()
            ->with(['workOrder:id,code,team_type,work_type', 'actor:id,username,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (WorkOrderStatusLog $log) => [
                'id' => $log->id,
                'work_order_id' => $log->work_order_id,
                'code' => $log->workOrder?->code,
                'action' => $log->action,
                'action_label' => WorkOrderStatusLog::ACTION_LABELS[$log->action] ?? $log->action,
                'to_status' => $log->to_status,
                'actor' => $log->actor?->displayName() ?? 'Sistem',
                'at' => $log->created_at?->copy()->timezone(ShiftService::TIMEZONE)->format('d/m H:i'),
            ])
            ->all();
    }
}
