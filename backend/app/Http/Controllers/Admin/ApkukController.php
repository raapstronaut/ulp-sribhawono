<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Apkuk;
use App\Models\ApkukReconciliationItem;
use App\Models\AuditLog;
use App\Models\Penyulang;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukSectionService;
use App\Services\Apkuk\ApkukSourceState;
use App\Services\ApkukCandidateService;
use App\Support\DataLoader;
use App\Support\PenyulangName;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * APKUK: laporan sumber SPS BOS yang aktif (hasil sinkronisasi dan rekonsiliasi), histori
 * laporan yang tidak lagi ada di sumber (source_missing_at), kebutuhan pemasangan, dan
 * progres pemasangan dari WO. Tidak menulis ke apkuks maupun Google Sheets; satu-satunya
 * penulisan adalah koreksi Section internal (apkuk_section_overrides) yang tercatat di audit.
 */
class ApkukController extends Controller
{
    private const PER_PAGE = 25;

    public const TABS = ['aktif' => 'Laporan aktif', 'menunggu' => 'Menunggu rekonsiliasi', 'histori' => 'Histori (diarsipkan)', 'progres' => 'Progres pemasangan WO'];

    public function index(Request $request, ApkukCandidateService $candidates, DataLoader $load): View
    {
        $tab = QueryInput::choice($request, 'tab', array_keys(self::TABS), 'aktif');
        $filters = [
            'penyulang' => QueryInput::text($request, 'penyulang', 100, trim: false),
            'status' => QueryInput::text($request, 'status', 60, trim: false),
            'q' => QueryInput::text($request, 'q'),
        ];
        $like = '%'.mb_strtoupper($filters['q']).'%';

        $evaluated = $load->attempt('candidates', fn () => $candidates->evaluate(), collect());
        $summary = $load->attempt('summary', fn () => [
            'active' => ApkukSourceState::active(DB::table('apkuks'))->count(),
            'pending' => ApkukSourceState::pending(DB::table('apkuks'))->count(),
            'history' => ApkukSourceState::history(DB::table('apkuks'))->count(),
            'last_synced_at' => DB::table('apkuks')->max('last_synced_at'),
            'eligible' => $evaluated->filter->isEligible()->count(),
            'need_bh' => (int) $evaluated->filter->isEligible()->sum(fn ($c) => $c->targetQuantity()),
            'wo_active' => WorkOrder::query()->apkuk()->active()->count(),
            'installed_bh' => (int) DB::table('work_order_apkuk_targets')->sum('installed_quantity'),
            'wo_done' => WorkOrder::query()->apkuk()->where('status', WorkOrderStatus::Selesai->value)->count(),
        ]);

        $rows = null;
        if ($tab !== 'progres') {
            $rows = $load->attempt('rows', fn () => (match ($tab) {
                'aktif' => ApkukSourceState::active(DB::table('apkuks')),
                'menunggu' => ApkukSourceState::pending(DB::table('apkuks')),
                default => ApkukSourceState::history(DB::table('apkuks')),
            })
                ->when($filters['penyulang'] !== '', fn ($q) => $q->where('penyulang', $filters['penyulang']))
                ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(no_tiang)'), 'like', $like)
                    ->orWhere(DB::raw('upper(source_id)'), 'like', $like)))
                ->orderByDesc('tanggal')
                ->orderBy('no_tiang')
                ->paginate(self::PER_PAGE, ['id', 'source_id', 'tanggal', 'penyulang', 'section', 'no_tiang', 'terpasang_bh', 'kurang_bh', 'status', 'latitude', 'longitude', 'source_missing_at'])
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
        } else {
            $rows = $load->attempt('rows', fn () => WorkOrder::query()->apkuk()
                ->with(['apkukTarget', 'posko'])
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(code)'), 'like', $like)
                    ->orWhereHas('apkukTarget', fn ($t) => $t->where(DB::raw('upper(no_tiang)'), 'like', $like))))
                ->orderByDesc('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
        }

        return view('admin.apkuk.index', [
            'tab' => $tab,
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($v) => $v !== ''),
            'summary' => $summary,
            'rows' => $rows,
            'candidates' => $evaluated,
            'issueLabels' => ApkukCandidateService::LABELS,
            'penyulangOptions' => $load->attempt('penyulangs', fn () => DB::table('apkuks')->whereNotNull('penyulang')->distinct()->orderBy('penyulang')->pluck('penyulang', 'penyulang')->all(), []),
            'statusOptions' => $load->attempt('statuses', fn () => DB::table('apkuks')->whereNotNull('status')->distinct()->orderBy('status')->pluck('status', 'status')->all(), []),
            'masterPenyulangCount' => Penyulang::query()->where('is_active', true)->count(),
            'sectionOverrides' => $tab === 'progres' || $load->failed('rows') ? [] : $load->attempt('overrides', fn () => ApkukSectionService::activeOverrides($rows->getCollection()->pluck('id')->all()), []),
            'load' => $load,
        ]);
    }

    /**
     * Detail satu laporan: 12 kolom APKUK_RAW (A–L) apa adanya, nilai turunan sistem dipisahkan,
     * status sumber, rekonsiliasi, WO terkait, laporan lain di tiang yang sama, dan koreksi Section.
     */
    public function show(Apkuk $apkuk, ApkukCandidateService $candidates, ApkukSectionService $sections, DataLoader $load): View
    {
        $apkuk->load([
            'reconciliationItems' => fn ($query) => $query->with('resolvedBy:id,name,username')->orderByDesc('id'),
            'sectionOverride.updatedBy:id,name,username',
        ]);
        $state = match (true) {
            $apkuk->source_missing_at !== null => 'histori',
            $apkuk->reconciliationItems->contains(fn (ApkukReconciliationItem $item) => $item->isOpen()) => 'menunggu',
            default => 'aktif',
        };
        $override = $apkuk->sectionOverride;
        $canonical = PenyulangName::canonical($apkuk->penyulang);

        return view('admin.apkuk.show', [
            'apkuk' => $apkuk,
            'state' => $state,
            'canonicalPenyulang' => $canonical,
            'masterPenyulang' => $canonical === '' ? null : $load->attempt('penyulang', fn () => Penyulang::query()
                ->where(fn ($query) => $query
                    ->where(DB::raw(PenyulangName::sqlNormalized('kode_penyulang')), $canonical)
                    ->orWhere(DB::raw(PenyulangName::sqlNormalized('nama_penyulang')), $canonical))
                ->first()),
            'section' => ApkukSectionService::effective($apkuk->section, $override?->isActive() ? $override->section_name : null),
            'override' => $override,
            'sectionReferences' => ApkukSectionService::isPlaceholder($apkuk->section) ? $load->attempt('references', fn () => $sections->references($apkuk), []) : [],
            'sectionHistory' => $load->attempt('section_history', fn () => AuditLog::query()
                ->where('entity_type', 'apkuk_section_override')
                ->where('entity_id', (string) $apkuk->id)
                ->with('actor:id,name,username')
                ->orderByDesc('id')
                ->get(), collect()),
            'candidate' => $state === 'histori' ? null : $load->attempt('candidate', fn () => $candidates->find($apkuk->id)),
            'workOrderTargets' => $load->attempt('work_orders', fn () => $apkuk->workOrderTargets()
                ->with('workOrder.posko')
                ->orderByDesc('created_at')
                ->get(), collect()),
            'samePole' => $load->attempt('same_pole', fn () => $this->samePoleReports($apkuk, $canonical), collect()),
            'load' => $load,
        ]);
    }

    public function updateSection(Request $request, Apkuk $apkuk, ApkukSectionService $sections): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['set', 'revoke'])],
            'section_name' => ['nullable', 'required_if:action,set', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($data['action'] === 'set') {
            $override = $sections->set($apkuk, $request->user(), (string) $data['section_name'], $data['reason']);
            $message = "Section efektif diisi {$override->section_name}. Nilai sumber tetap tersimpan.";
        } else {
            $sections->revoke($apkuk, $request->user(), $data['reason']);
            $message = 'Koreksi Section dicabut; Section efektif kembali mengikuti nilai sumber.';
        }

        return redirect()->route('admin.apkuk.show', $apkuk)->with('flash', $message);
    }

    /**
     * Laporan lain untuk tiang yang sama (no. tiang ternormalisasi, penyulang canonical sama).
     *
     * @return Collection<int, object>
     */
    private function samePoleReports(Apkuk $apkuk, string $canonicalPenyulang): Collection
    {
        $rows = DB::table('apkuks')
            ->where('id', '!=', $apkuk->id)
            ->where(DB::raw('upper(trim(no_tiang))'), ApkukCandidateService::normalizePole($apkuk->no_tiang))
            ->whereIn(DB::raw(PenyulangName::sqlNormalized('penyulang')), PenyulangName::spellings($canonicalPenyulang))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'source_id', 'tanggal', 'status', 'terpasang_bh', 'kurang_bh', 'source_missing_at']);
        $pending = ApkukReconciliationItem::query()->open()->whereIn('apkuk_id', $rows->pluck('id'))->pluck('apkuk_id')->flip();

        return $rows->map(function (object $row) use ($pending): object {
            $row->state = $row->source_missing_at !== null ? 'histori' : (isset($pending[$row->id]) ? 'menunggu' : 'aktif');

            return $row;
        });
    }
}
