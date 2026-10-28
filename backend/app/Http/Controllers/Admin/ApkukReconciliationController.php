<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Apkuk;
use App\Models\ApkukReconciliationItem;
use App\Models\ApkukSyncRun;
use App\Services\Apkuk\ApkukReconciliationQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Antrean rekonsiliasi APKUK: laporan yang tidak lagi ada di APKUK_RAW dan identitas baru
 * yang ditahan, beserta riwayat apkuk:sync. Keputusan hanya oleh Admin aktif yang login.
 */
class ApkukReconciliationController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $kind = in_array($request->query('jenis'), array_keys(ApkukReconciliationItem::KIND_LABELS), true) ? $request->query('jenis') : null;

        $items = ApkukReconciliationItem::query()
            ->open()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $apkukIds = collect($items->items())
            ->flatMap(fn (ApkukReconciliationItem $item) => $item->identityCandidates() ?: [(int) $item->apkuk_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('admin.apkuk-reconciliation.index', [
            'items' => $items,
            'kind' => $kind,
            'apkuks' => Apkuk::query()->whereIn('id', $apkukIds)->get()->keyBy('id'),
            'workOrders' => ApkukReconciliationQueue::workOrderRefs($apkukIds),
            'summary' => [
                'missing' => ApkukReconciliationItem::query()->open()->where('kind', ApkukReconciliationItem::KIND_MISSING)->count(),
                'identity' => ApkukReconciliationItem::query()->open()->where('kind', ApkukReconciliationItem::KIND_IDENTITY_CHANGE)->count(),
                'resolved' => ApkukReconciliationItem::query()->where('status', ApkukReconciliationItem::STATUS_RESOLVED)->count(),
            ],
            'lastRun' => ApkukSyncRun::query()->orderByDesc('id')->first(),
            'lastAccepted' => ApkukSyncRun::lastAccepted(),
            'runs' => ApkukSyncRun::query()->orderByDesc('id')->limit(10)->get(),
            'resolved' => ApkukReconciliationItem::query()
                ->where('status', ApkukReconciliationItem::STATUS_RESOLVED)
                ->with('resolvedBy:id,name,username')
                ->orderByDesc('resolved_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function resolve(Request $request, int $item, ApkukReconciliationQueue $queue): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(ApkukReconciliationQueue::DECISIONS))],
            'reason' => ['required', 'string', 'min:'.ApkukReconciliationQueue::MIN_REASON, 'max:'.ApkukReconciliationQueue::MAX_REASON],
            'apkuk_id' => ['nullable', 'integer'],
            'acknowledge_active_wo' => ['nullable', 'boolean'],
        ], [
            'decision.required' => 'Pilih keputusan.',
            'decision.in' => 'Keputusan tidak dikenal.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan minimal :min karakter.',
            'reason.max' => 'Alasan maksimal :max karakter.',
            'apkuk_id.integer' => 'Laporan lama tidak valid.',
        ]);

        $resolved = $queue->resolve(
            $request->user(),
            $item,
            $data['decision'],
            $data['reason'],
            isset($data['apkuk_id']) ? (int) $data['apkuk_id'] : null,
            (bool) ($data['acknowledge_active_wo'] ?? false),
        );

        return redirect()
            ->route('admin.apkuk-reconciliation.index', array_filter(['jenis' => $request->query('jenis')]))
            ->with('status', "Antrean #{$resolved->id} diselesaikan: {$resolved->resolutionLabel()}.");
    }

    public function resolveMany(Request $request, ApkukReconciliationQueue $queue): RedirectResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:'.ApkukReconciliationQueue::MAX_BATCH],
            'item_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:'.ApkukReconciliationQueue::MIN_REASON, 'max:'.ApkukReconciliationQueue::MAX_REASON],
            'acknowledge_active_wo' => ['nullable', 'boolean'],
        ], [
            'item_ids.required' => 'Pilih minimal satu laporan hilang.',
            'item_ids.max' => 'Maksimal :max antrean per konfirmasi.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan minimal :min karakter.',
            'reason.max' => 'Alasan maksimal :max karakter.',
        ]);

        $count = $queue->confirmRemovedMany($request->user(), $data['item_ids'], $data['reason'], (bool) ($data['acknowledge_active_wo'] ?? false));

        return redirect()
            ->route('admin.apkuk-reconciliation.index', array_filter(['jenis' => $request->query('jenis')]))
            ->with('status', "{$count} laporan dikonfirmasi dihapus di sumber dan menjadi histori.");
    }
}
