<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P0Item;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Services\Pasila\P0Service;
use App\Services\ShiftService;
use App\Services\Work\PoskoWorkOrderCrewReview;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Monitoring dan keputusan P0 POSKO (Batch B3.3): usulan SYSTEM menunggu ACC, ditolak menunggu
 * pengganti, aktif, menunggu verifikasi, dan selesai. P0 manual dibuat dari detail sesi PASILA.
 * Status P0 terpisah dari WO APKUK; pelaksanaan memakai WO P0.
 */
class P0Controller extends Controller
{
    private const LIMIT = 300;

    public function index(Request $request, P0Service $p0, DataLoader $load): View
    {
        $stage = QueryInput::choice($request, 'stage', array_keys(P0Item::STAGE_LABELS));
        $poskoId = QueryInput::uuid($request, 'posko_id');
        $items = $load->attempt('items', fn () => P0Item::query()
            ->with(['session.shiftInstance', 'posko', 'penyulang', 'workOrder', 'replacement:id,code,replaces_p0_item_id'])
            ->when($poskoId !== '', fn ($q) => $q->where('posko_id', $poskoId))
            ->latest()
            ->limit(self::LIMIT)
            ->get(), collect());

        return view('admin.p0.index', [
            'items' => $stage === '' ? $items : $items->filter(fn (P0Item $item) => $item->stage() === $stage)->values(),
            'counts' => $items->countBy(fn (P0Item $item) => $item->stage())->all(),
            'filters' => ['stage' => $stage, 'posko_id' => $poskoId],
            'stageOptions' => P0Item::STAGE_LABELS,
            'poskoOptions' => $load->attempt('poskos', fn () => Posko::query()->where('is_active', true)->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(), []),
            'limit' => self::LIMIT,
            'strategy' => $p0->strategy(),
            'load' => $load,
        ]);
    }

    public function show(P0Item $p0Item, ShiftService $shifts, PoskoWorkOrderCrewReview $crewReview): View
    {
        $p0Item->load(['session.shiftInstance', 'session.posko', 'posko', 'penyulang', 'workOrder', 'replaces', 'replacement', 'createdBy:id,name,username', 'reviewedBy:id,name,username']);

        return view('admin.p0.show', [
            'crewAudit' => $p0Item->workOrder ? $crewReview->forWorkOrder($p0Item->workOrder) : null,
            'item' => $p0Item,
            'schedule' => $this->defaultSchedule($p0Item->session, $shifts),
            'shiftOptions' => $this->shiftOptions($shifts),
        ]);
    }

    public function approve(Request $request, P0Item $p0Item, P0Service $p0, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'shift_code' => ['required', Rule::in($shifts->codes())],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $item = $p0->approve($p0Item, $request->user(), $data['work_date'], $data['shift_code'], $data['review_note'] ?? null);

        return redirect()->route('admin.p0.show', $item)->with('flash', "Usulan {$item->code} di-ACC; WO P0 {$item->workOrder->code} dibuat untuk POSKO.");
    }

    public function reject(Request $request, P0Item $p0Item, P0Service $p0): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'max:1000']]);
        $item = $p0->reject($p0Item, $request->user(), $data['review_note']);

        return redirect()->route('admin.p0.show', $item)->with('flash', "Usulan {$item->code} ditolak. Buat P0 manual pengganti dari sesi PASILA yang sama.");
    }

    public function store(Request $request, PasilaSession $pasilaSession, P0Service $p0, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'shift_code' => ['required', Rule::in($shifts->codes())],
            'replaces_p0_item_id' => ['nullable', 'uuid'],
        ]);
        [$type, $id] = array_pad(explode(':', $data['source'], 2), 2, '');
        if (! array_key_exists($type, P0Item::SOURCE_LABELS) || $id === '') {
            throw ValidationException::withMessages(['source' => 'Pilih sumber P0 dari daftar sumber yang sah.']);
        }

        $item = $p0->createManual($pasilaSession, $request->user(), [
            'source_type' => $type,
            'source_id' => $id,
            'description' => $data['description'],
            'work_date' => $data['work_date'],
            'shift_code' => $data['shift_code'],
            'replaces_p0_item_id' => $data['replaces_p0_item_id'] ?? null,
        ]);

        return redirect()->route('admin.p0.show', $item)->with('flash', "P0 manual {$item->code} dibuat; WO P0 {$item->workOrder->code} ditugaskan ke POSKO.");
    }

    /**
     * Jadwal bawaan WO P0: shift sesi PASILA bila belum berakhir, selain itu shift yang sedang berjalan.
     *
     * @return array{work_date: string, shift_code: string}
     */
    public static function defaultSchedule(PasilaSession $session, ShiftService $shifts): array
    {
        if (! $session->shiftHasEnded()) {
            return ['work_date' => $session->shiftInstance->operational_date->toDateString(), 'shift_code' => $session->shiftInstance->shift_code];
        }
        $current = $shifts->instanceAt();

        return ['work_date' => $current['operational_date'], 'shift_code' => $current['code']];
    }

    /**
     * @return array<string, string>
     */
    private function shiftOptions(ShiftService $shifts): array
    {
        return collect($shifts->codes())->mapWithKeys(fn ($code) => [$code => 'Shift '.$code.' ('.$shifts->describe($code)['time'].')'])->all();
    }
}
