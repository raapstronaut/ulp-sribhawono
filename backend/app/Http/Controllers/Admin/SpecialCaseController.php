<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderReschedule;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderReviewService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Frekuensi gangguan mentah dari tabel `gangguans` sebagai bahan Special Case.
 *
 * Metode, normalisasi, ambang, dan tindak lanjut Special Case masih TBD:
 * halaman ini tidak menandai special case dan tidak membuat P0/WO.
 */
class SpecialCaseController extends Controller
{
    public const PERIODS = [30 => '30 hari', 90 => '90 hari', 180 => '180 hari', 365 => '365 hari'];

    public const DIMENSIONS = ['penyulang' => 'Penyulang', 'peralatan' => 'Peralatan', 'no_tiang' => 'No. tiang'];

    private const DEFAULT_PERIOD = 90;

    public function index(Request $request, ShiftService $shifts, DataLoader $load): View
    {
        $filters = $this->filters($request);
        $range = $this->range($filters['period'], $shifts);
        $column = $filters['dimension'];

        $groups = $load->attempt('groups', fn () => $this->inRange($range)
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where(DB::raw("upper({$column})"), 'like', '%'.mb_strtoupper($filters['q']).'%'))
            ->select([
                "{$column} as group_key",
                DB::raw('count(*) as total'),
                DB::raw('max(tanggal_kejadian) as last_at'),
                DB::raw('sum(lama_padam_menit) as total_padam'),
            ])
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->paginate(25)
            ->withQueryString()
            ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(25));

        $summary = $load->attempt('summary', fn () => [
            'total' => $this->inRange($range)->count(),
            'missing_dimension' => $this->inRange($range)->where(fn (Builder $query) => $query->whereNull($column)->orWhere($column, ''))->count(),
            'without_date' => DB::table('gangguans')->whereNull('tanggal_kejadian')->count(),
            'last_synced_at' => DB::table('gangguans')->max('last_synced_at'),
        ]);

        return view('admin.special-case.index', [
            // Special case pelaksanaan WO: kendala, reschedule, limpahan, keterlambatan.
            'issues' => $load->attempt('issues', fn () => WorkOrderIssue::query()
                ->with(['workOrder.posko', 'reportedByTeam', 'reportedBy'])
                ->where('status', WorkOrderIssue::STATUS_TERBUKA)
                ->orderBy('created_at')
                ->paginate(10, ['*'], 'issues_page')
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(10, 'issues_page')),
            'exceptionCounts' => $load->attempt('exception_counts', fn () => [
                'issues' => WorkOrderIssue::query()->where('status', WorkOrderIssue::STATUS_TERBUKA)->count(),
                'reschedules' => WorkOrderReschedule::query()->where('status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)->count(),
                'escalations' => WorkEscalation::query()->where('status', WorkEscalation::STATUS_MENUNGGU_ACC)->count(),
                'overdue' => WorkOrder::query()->overdue()->count(),
            ]),
            'overdue' => $load->attempt('overdue', fn () => WorkOrder::query()->overdue()->with(['posko', 'penyulang'])->orderBy('due_at')->limit(10)->get(), collect()),
            'resolutionOptions' => WorkOrderIssue::RESOLUTION_LABELS,
            'filters' => $filters,
            'hasFilter' => $request->hasAny(['period', 'dimension', 'q']),
            'range' => $range,
            'groups' => $groups,
            'summary' => $summary,
            'load' => $load,
        ]);
    }

    public function resolveIssue(Request $request, WorkOrderIssue $issue, WorkOrderReviewService $review): RedirectResponse
    {
        $data = $request->validate([
            'resolution_action' => ['required', 'string', 'max:24'],
            'resolution_note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $review->resolveIssue($issue, $request->user(), $data['resolution_action'], $data['resolution_note']);

        return back()->with('flash', "Kendala pada {$issue->workOrder->code} ditindaklanjuti.");
    }

    public function show(Request $request, ShiftService $shifts, DataLoader $load): View
    {
        $filters = $this->filters($request);
        $key = QueryInput::text($request, 'key', 255, trim: false);
        $range = $this->range($filters['period'], $shifts);
        $column = $filters['dimension'];

        $events = $load->attempt('events', fn () => $this->inRange($range)
            ->when(
                $key === '',
                fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull($column)->orWhere($column, '')),
                fn (Builder $query) => $query->where($column, $key),
            )
            ->orderByDesc('tanggal_kejadian')
            ->orderBy('id_gangguan')
            ->paginate(20, [
                'id_gangguan', 'tanggal_kejadian', 'penyulang', 'peralatan', 'no_tiang', 'kode_gangguan',
                'sifat_gangguan', 'penyebab', 'uraian_penyebab', 'lama_padam_menit', 'tindakan_corrective',
            ])
            ->withQueryString()
            ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(20));

        return view('admin.special-case.show', [
            'filters' => $filters,
            'key' => $key,
            'range' => $range,
            'events' => $events,
            'load' => $load,
        ]);
    }

    /**
     * @return array{period: int, dimension: string, q: string}
     */
    private function filters(Request $request): array
    {
        return [
            'period' => QueryInput::integerChoice($request, 'period', array_keys(self::PERIODS), self::DEFAULT_PERIOD),
            // Kolom hanya dari daftar putih karena dipakai langsung di SQL.
            'dimension' => QueryInput::choice($request, 'dimension', array_keys(self::DIMENSIONS), 'penyulang'),
            'q' => QueryInput::text($request, 'q'),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function range(int $period, ShiftService $shifts): array
    {
        $today = CarbonImmutable::parse($shifts->today(), ShiftService::TIMEZONE);

        return [
            'from' => $today->subDays($period - 1)->toDateString(),
            'to' => $today->toDateString(),
        ];
    }

    private function inRange(array $range): Builder
    {
        return DB::table('gangguans')->whereBetween('tanggal_kejadian', [$range['from'], $range['to']]);
    }
}
