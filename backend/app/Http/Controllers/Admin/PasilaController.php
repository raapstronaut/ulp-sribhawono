<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Pasila\PasilaSectionService;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Monitor PASILA per Posko dan shift (Batch B3.1).
 *
 * Tiga hal ditampilkan terpisah: sesi PASILA (pasila_sessions), status WO APKUK shift yang sama
 * (work_orders), dan kewajiban P0 (belum aktif, B3.3). Section sesi berasal dari referensi
 * terverifikasi SectionPoskoMap; Admin dapat menentukan/mengganti Section hanya ke Section milik
 * Posko tersebut.
 */
class PasilaController extends Controller
{
    public function index(Request $request, ShiftService $shifts, PasilaShiftOpener $opener, DataLoader $load): View
    {
        $context = $this->shiftContext($request, $shifts);
        $poskoId = QueryInput::uuid($request, 'posko_id');

        $poskos = $load->attempt('poskos', fn () => Posko::query()
            ->where('is_active', true)
            ->orderBy('nama_posko')
            ->get(), collect());

        $sessions = $load->attempt('sessions', fn () => $this->sessionsFor($context), collect());
        $map = $load->attempt('sections', fn () => SectionPoskoMap::load());
        $entries = $map?->entries() ?? [];

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
            ->map(function (Posko $posko) use ($progressRows, $pendingReschedules, $sessions, $map): array {
                $statusRows = $progressRows->where('posko_id', $posko->id);
                $session = $sessions->get($posko->id);

                return [
                    'posko' => $posko,
                    'session' => $session,
                    'section_check' => $session && $map ? $this->sectionCheck($map, $session) : null,
                    'total' => (int) $statusRows->sum('total'),
                    'selesai' => (int) $statusRows->filter(fn ($row) => $row->status === WorkOrderStatus::Selesai)->sum('total'),
                    'aktif' => (int) $statusRows->filter(fn ($row) => $row->status->isActive())->sum('total'),
                    'reschedule' => (int) ($pendingReschedules[$posko->id] ?? 0),
                ];
            })
            ->values();

        $verifiedByPosko = collect($entries)->whereNotNull('posko')->groupBy(fn (array $entry) => $entry['posko']->getKey());

        return view('admin.pasila.index', [
            'context' => $context,
            'filters' => ['posko_id' => $poskoId, 'work_date' => $context['work_date'], 'shift_code' => $context['shift_code']],
            'hasFilter' => $request->hasAny(['posko_id', 'work_date', 'shift_code']),
            'rows' => $rows,
            'poskos' => $poskos,
            'poskoOptions' => $poskos->pluck('nama_posko', 'id')->all(),
            'shiftOptions' => $this->shiftOptions($shifts),
            'targetPerShift' => ApkukShiftQuota::TARGET_PER_SHIFT,
            'verifiedByPosko' => $verifiedByPosko,
            'heldEntries' => collect($entries)->whereNull('posko')->values(),
            'blockedReason' => $opener->blockedReason(),
            'strategy' => $opener->strategy(),
            'totals' => [
                'poskos' => $poskos->count(),
                'sessions' => $sessions->count(),
                'waiting' => $sessions->where('section_status', PasilaSession::SECTION_MENUNGGU)->count(),
                'work_orders' => (int) $rows->sum('total'),
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

        $session = $load->attempt('sessions', fn () => $this->sessionsFor($context)->get($posko->id));
        $map = $load->attempt('sections', fn () => SectionPoskoMap::load());
        $sections = $map ? $map->sectionsForPosko($posko->getKey()) : [];
        $lastAssigned = $load->attempt('history', fn () => PasilaSession::query()
            ->join('shift_instances as s', 's.id', '=', 'pasila_sessions.shift_instance_id')
            ->where('pasila_sessions.posko_id', $posko->getKey())
            ->whereNotNull('pasila_sessions.section_key')
            ->orderByDesc('s.starts_at')
            ->get(['pasila_sessions.section_key', 's.operational_date', 's.shift_code'])
            ->unique('section_key')
            ->keyBy('section_key'), collect());

        return view('admin.pasila.show', [
            'posko' => $posko,
            'context' => $context,
            'filters' => ['work_date' => $context['work_date'], 'shift_code' => $context['shift_code']],
            'shiftOptions' => $this->shiftOptions($shifts),
            'targetPerShift' => ApkukShiftQuota::TARGET_PER_SHIFT,
            'session' => $session,
            'sectionCheck' => $session && $map ? $this->sectionCheck($map, $session) : null,
            'sections' => $sections,
            'sectionOptions' => collect($sections)->mapWithKeys(fn (array $entry) => [$entry['section'] => $entry['section'].' — '.$entry['penyulang']])->all(),
            'lastAssigned' => $lastAssigned,
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

    public function open(Request $request, ShiftService $shifts, PasilaShiftOpener $opener): RedirectResponse
    {
        $result = $opener->openCurrent(PasilaSession::TRIGGER_ADMIN_WEB, $request->user());
        $current = $shifts->instanceAt();
        $redirect = redirect()->route('admin.pasila.index', ['work_date' => $current['operational_date'], 'shift_code' => $current['code']]);

        if ($result['status'] !== 'COMPLETED') {
            return $redirect->withErrors(['opener' => $result['message']]);
        }

        return $redirect->with('flash', count($result['created']) > 0
            ? count($result['created']).' sesi PASILA dibuka untuk shift berjalan; '.$result['existing'].' sesi sudah ada sebelumnya.'
            : 'Semua Posko aktif sudah memiliki sesi PASILA untuk shift berjalan; tidak ada sesi baru.');
    }

    public function assignSection(Request $request, PasilaSession $pasilaSession, PasilaSectionService $sections): RedirectResponse
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $session = $sections->assign($pasilaSession, $request->user(), $data['section'], $data['reason']);
        $shift = $session->shiftInstance;

        return redirect()
            ->route('admin.pasila.show', ['posko' => $session->posko_id, 'work_date' => $shift->operational_date->toDateString(), 'shift_code' => $shift->shift_code])
            ->with('flash', 'Section sesi '.$session->code.' ditetapkan: '.$session->section_name.'.');
    }

    /**
     * @return array{work_date: string, shift_code: string, shift: array{code: string, name: string, time: string, index: string}, is_current: bool, phase: string, window: array{start: CarbonImmutable, end: CarbonImmutable}}
     */
    private function shiftContext(Request $request, ShiftService $shifts): array
    {
        $current = $shifts->current();
        $workDate = QueryInput::date($request, 'work_date') ?: $shifts->operationalDate();
        $shiftCode = QueryInput::choice($request, 'shift_code', $shifts->codes(), $current['code']);
        $window = $shifts->window($workDate, $shiftCode);
        $now = CarbonImmutable::now();

        return [
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
            'shift' => $shifts->describe($shiftCode),
            'is_current' => $workDate === $shifts->operationalDate() && $shiftCode === $current['code'],
            // Fase shift terhadap sekarang: BELUM_MULAI | BERJALAN | BERAKHIR.
            'phase' => $now->lt($window['start']) ? 'BELUM_MULAI' : ($now->lt($window['end']) ? 'BERJALAN' : 'BERAKHIR'),
            'window' => $window,
        ];
    }

    /**
     * @return Collection<string, PasilaSession> sesi shift ini per posko_id
     */
    private function sessionsFor(array $context): Collection
    {
        $instance = ShiftInstance::query()->whereDate('operational_date', $context['work_date'])->where('shift_code', $context['shift_code'])->first();

        return $instance === null
            ? collect()
            : PasilaSession::query()->where('shift_instance_id', $instance->id)->with(['penyulang', 'shiftInstance', 'sectionSetBy:id,name,username', 'openedBy:id,name,username'])->get()->keyBy('posko_id');
    }

    /**
     * Apakah Section sesi masih terverifikasi milik Posko yang sama pada referensi terkini.
     *
     * @return array{valid: bool, note: ?string}
     */
    private function sectionCheck(SectionPoskoMap $map, PasilaSession $session): array
    {
        if (! $session->hasSection()) {
            return ['valid' => false, 'note' => null];
        }
        $entry = $map->entry($session->section_key);
        if ($entry === null) {
            return ['valid' => false, 'note' => 'Section tidak lagi ada pada referensi SECTION_PIC_RAW terkini.'];
        }
        if ($entry['posko'] === null) {
            return ['valid' => false, 'note' => 'Referensi terkini menahan Section ini: '.(SectionPoskoMap::LABELS[$entry['reason']] ?? $entry['reason']).'.'];
        }
        if ($entry['posko']->getKey() !== $session->posko_id) {
            return ['valid' => false, 'note' => 'Referensi terkini mencatat Section ini milik Posko '.$entry['posko']->nama_posko.'.'];
        }

        return ['valid' => true, 'note' => null];
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
