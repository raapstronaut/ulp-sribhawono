<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Monitor PASILA per Posko dan shift.
 *
 * Sesi PASILA, temuan per titik, dan P0 terkait belum memiliki tabel domain,
 * sehingga halaman hanya menampilkan konteks shift dari master dan WO APKUK nyata.
 */
class PasilaController extends Controller
{
    private const APKUK_TARGET_PER_SHIFT = 6;

    public function index(Request $request, ShiftService $shifts, DataLoader $load): View
    {
        $context = $this->shiftContext($request, $shifts);
        $poskoId = QueryInput::uuid($request, 'posko_id');

        $poskos = $load->attempt('poskos', fn () => Posko::query()
            ->where('is_active', true)
            ->orderBy('nama_posko')
            ->get(), collect());

        $progressRows = $load->attempt('work_orders', fn () => $this->shiftWorkOrders($context)
            ->where('status', '!=', WorkOrderStatus::Dibatalkan->value)
            ->select('posko_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('posko_id', 'status')
            ->get(), collect());

        $pendingReschedules = $load->attempt('reschedules', fn () => DB::table('work_order_reschedules as r')
            ->join('work_orders as w', 'w.id', '=', 'r.work_order_id')
            ->where('r.status', WorkOrderReschedule::STATUS_MENUNGGU_ACC)
            ->where('w.team_type', WorkOrder::TEAM_POSKO)
            ->where('w.work_type', WorkOrder::TYPE_PEMASANGAN_APKUK)
            ->whereDate('w.work_date', $context['work_date'])
            ->where('w.shift_code', $context['shift_code'])
            ->select('w.posko_id', DB::raw('count(*) as total'))
            ->groupBy('w.posko_id')
            ->pluck('total', 'posko_id'), collect());

        $rows = $poskos
            ->when($poskoId !== '', fn ($collection) => $collection->where('id', $poskoId))
            ->map(function (Posko $posko) use ($progressRows, $pendingReschedules): array {
                $statusRows = $progressRows->where('posko_id', $posko->id);

                return [
                    'posko' => $posko,
                    'total' => (int) $statusRows->sum('total'),
                    'selesai' => (int) $statusRows->filter(fn ($row) => $row->status === WorkOrderStatus::Selesai)->sum('total'),
                    'aktif' => (int) $statusRows->filter(fn ($row) => $row->status->isActive())->sum('total'),
                    'reschedule' => (int) ($pendingReschedules[$posko->id] ?? 0),
                ];
            })
            ->values();

        return view('admin.pasila.index', [
            'context' => $context,
            'filters' => ['posko_id' => $poskoId, 'work_date' => $context['work_date'], 'shift_code' => $context['shift_code']],
            'hasFilter' => $request->hasAny(['posko_id', 'work_date', 'shift_code']),
            'rows' => $rows,
            'poskoOptions' => $poskos->pluck('nama_posko', 'id')->all(),
            'shiftOptions' => $this->shiftOptions($shifts),
            'targetPerShift' => self::APKUK_TARGET_PER_SHIFT,
            'totals' => [
                'poskos' => $poskos->count(),
                'work_orders' => (int) $rows->sum('total'),
                'selesai' => (int) $rows->sum('selesai'),
                'target' => $rows->count() * self::APKUK_TARGET_PER_SHIFT,
            ],
            'load' => $load,
        ]);
    }

    public function show(Request $request, Posko $posko, ShiftService $shifts, DataLoader $load): View
    {
        $context = $this->shiftContext($request, $shifts);
        $constrain = fn (Builder $query) => $query
            ->where('team_type', WorkOrder::TEAM_POSKO)
            ->where('work_type', WorkOrder::TYPE_PEMASANGAN_APKUK)
            ->where('posko_id', $posko->id)
            ->whereDate('work_date', $context['work_date'])
            ->where('shift_code', $context['shift_code']);

        return view('admin.pasila.show', [
            'posko' => $posko,
            'context' => $context,
            'filters' => ['work_date' => $context['work_date'], 'shift_code' => $context['shift_code']],
            'shiftOptions' => $this->shiftOptions($shifts),
            'targetPerShift' => self::APKUK_TARGET_PER_SHIFT,
            'penyulangs' => $load->attempt('penyulangs', fn () => $posko->penyulangs()->orderBy('kode_penyulang')->get(), collect()),
            'fieldTeams' => $load->attempt('teams', fn () => FieldTeam::query()
                ->where('posko_id', $posko->id)
                ->withCount(['personnel' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(), collect()),
            'workOrders' => $load->attempt('work_orders', fn () => $constrain(WorkOrder::query())
                ->with(['posko', 'penyulang', 'parent'])
                ->orderBy('code')
                ->paginate(20)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(20)),
            'reschedules' => $load->attempt('reschedules', fn () => WorkOrderReschedule::query()
                ->with('workOrder')
                ->whereHas('workOrder', $constrain)
                ->latest()
                ->limit(20)
                ->get(), collect()),
            'load' => $load,
        ]);
    }

    /**
     * @return array{work_date: string, shift_code: string, shift: array{code: string, name: string, time: string, index: string}, is_current: bool}
     */
    private function shiftContext(Request $request, ShiftService $shifts): array
    {
        $current = $shifts->current();
        $workDate = QueryInput::date($request, 'work_date') ?: $shifts->operationalDate();
        $shiftCode = QueryInput::choice($request, 'shift_code', $shifts->codes(), $current['code']);

        return [
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
            'shift' => $shifts->describe($shiftCode),
            'is_current' => $workDate === $shifts->operationalDate() && $shiftCode === $current['code'],
        ];
    }

    private function shiftWorkOrders(array $context): Builder
    {
        return WorkOrder::query()
            ->apkuk()
            ->whereDate('work_date', $context['work_date'])
            ->where('shift_code', $context['shift_code']);
    }

    /**
     * @return array<string, string>
     */
    private function shiftOptions(ShiftService $shifts): array
    {
        $options = [];
        foreach ($shifts->codes() as $code) {
            $shift = $shifts->describe($code);
            $options[$code] = "Shift {$code} ({$shift['name']}, {$shift['time']})";
        }

        return $options;
    }
}
