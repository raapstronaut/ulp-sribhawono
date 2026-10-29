<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApkukGenerationRun;
use App\Models\ApkukShiftAllocation;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Services\Apkuk\Generation\ApkukShiftGenerator;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Monitoring kuota enam WO APKUK per Posko per shift. Membedakan tiga hal:
 * WO nyata yang tersimpan (limpahan, WO lain, buatan generator), hasil pratinjau terakhir
 * (simulasi tanpa WO), dan eksekusi generate (hanya bila pengaman aktivasi mengizinkan).
 * Angka alokasi generate adalah catatan beku saat generate; WO nyata terkini ditampilkan terpisah.
 */
class ShiftQuotaController extends Controller
{
    public function index(Request $request, ShiftService $shifts, ApkukShiftQuota $quota, ApkukShiftGenerator $generator, DataLoader $load): View
    {
        $current = $shifts->instanceAt();
        $date = QueryInput::date($request, 'operational_date') ?: $current['operational_date'];
        $code = QueryInput::choice($request, 'shift_code', $shifts->codes(), $current['code']);
        $window = $shifts->window($date, $code);
        $instance = $load->attempt('instance', fn () => ShiftInstance::query()->whereDate('operational_date', $date)->where('shift_code', $code)->first());

        $scheduled = $load->attempt('scheduled', fn () => $quota->scheduled($date, $code), []);
        $allocations = $instance ? $load->attempt('allocations', fn () => ApkukShiftAllocation::query()->where('shift_instance_id', $instance->id)->get()->keyBy('posko_id'), collect()) : collect();
        $rows = $load->attempt('poskos', fn () => Posko::query()->where('is_active', true)->orderBy('nama_posko')->get(), collect())
            ->map(function (Posko $posko) use ($scheduled, $allocations): array {
                $carry = $scheduled[$posko->id]['carry_over'] ?? collect();
                $existing = $scheduled[$posko->id]['existing'] ?? collect();
                $generated = $existing->whereNotNull('generation_run_id');
                $total = $carry->count() + $existing->count();
                $overload = max(0, $total - ApkukShiftQuota::TARGET_PER_SHIFT);
                $allocation = $allocations->get($posko->id);

                return [
                    'posko' => $posko,
                    'carry_over' => $carry,
                    'manual' => $existing->whereNull('generation_run_id'),
                    'generated' => $generated,
                    'total' => $total,
                    'open_slots' => max(0, ApkukShiftQuota::TARGET_PER_SHIFT - $total),
                    'overload' => $overload,
                    // WO non-limpahan dibatasi enam, jadi kelebihan yang sah tidak pernah melebihi jumlah limpahan.
                    'overload_from_carry_over' => $overload <= $carry->count(),
                    'allocation' => $allocation,
                    // Selisih WO nyata sekarang terhadap angka beku alokasi (WO manual, limpahan, pembatalan setelah generate).
                    'allocation_drift' => $allocation ? $total - $allocation->total_count : null,
                ];
            });

        $runs = $instance ? $load->attempt('runs', fn () => ApkukGenerationRun::query()->where('shift_instance_id', $instance->id)->with('triggeredBy:id,name,username')->latest('id')->limit(10)->get(), collect()) : collect();

        return view('admin.shift-quota.index', [
            'date' => $date,
            'code' => $code,
            'shift' => $shifts->describe($code),
            'window' => $window,
            'isCurrent' => $date === $current['operational_date'] && $code === $current['code'],
            'shiftOptions' => collect($shifts->codes())->mapWithKeys(fn ($c) => [$c => 'Shift '.$c.' ('.$shifts->describe($c)['time'].')'])->all(),
            'rows' => $rows,
            'target' => ApkukShiftQuota::TARGET_PER_SHIFT,
            'latestPreview' => $runs->firstWhere('mode', ApkukGenerationRun::MODE_PREVIEW),
            'latestGenerate' => $runs->firstWhere('mode', ApkukGenerationRun::MODE_GENERATE),
            'runs' => $runs,
            'ranking' => $generator->ranking(),
            'blockedReason' => $generator->blockedReason(),
            'environment' => (string) app()->environment(),
            'load' => $load,
        ]);
    }

    public function run(Request $request, ShiftService $shifts, ApkukShiftGenerator $generator): RedirectResponse
    {
        $data = $request->validate([
            'operational_date' => ['required', 'date_format:Y-m-d'],
            'shift_code' => ['required', Rule::in($shifts->codes())],
            'mode' => ['required', Rule::in([ApkukGenerationRun::MODE_PREVIEW, ApkukGenerationRun::MODE_GENERATE])],
        ]);

        $run = $generator->run($data['operational_date'], $data['shift_code'], $data['mode'], ApkukGenerationRun::TRIGGER_ADMIN_WEB, $request->user());
        $message = match ($run->status) {
            ApkukGenerationRun::STATUS_COMPLETED => $run->isPreview()
                ? 'Pratinjau selesai. Tidak ada WO yang dibuat.'
                : 'Generate selesai: '.collect($run->summary)->sum(fn ($row) => count($row['created'] ?? [])).' WO baru dibuat.',
            ApkukGenerationRun::STATUS_REFUSED => 'Ditolak pengaman: '.$run->message,
            default => 'Eksekusi gagal: '.$run->message,
        };

        $redirect = redirect()->route('admin.shift-quota.index', ['operational_date' => $data['operational_date'], 'shift_code' => $data['shift_code']]);

        return $run->status === ApkukGenerationRun::STATUS_COMPLETED
            ? $redirect->with('flash', $message)
            : $redirect->withErrors(['generator' => $message]);
    }
}
