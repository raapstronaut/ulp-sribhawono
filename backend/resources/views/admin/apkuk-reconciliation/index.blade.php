@extends('layouts.admin')

@section('title', 'Rekonsiliasi APKUK')

@section('content')
@php
    use App\Models\ApkukReconciliationItem;
    use App\Models\ApkukSyncRun;
    use App\Services\Apkuk\ApkukReconciliationQueue;

    $wib = fn ($time) => $time?->timezone('Asia/Jakarta')->format('d/m/Y H:i');
    $runClasses = [
        ApkukSyncRun::STATUS_SUCCESS => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        ApkukSyncRun::STATUS_SUCCESS_WITH_PENDING => 'bg-amber-50 text-amber-700 border-amber-200',
        ApkukSyncRun::STATUS_FAILED => 'bg-red-50 text-red-700 border-red-200',
        ApkukSyncRun::STATUS_RUNNING => 'bg-slate-100 text-slate-600 border-slate-200',
    ];
    $tabs = ['' => 'Semua'] + ApkukReconciliationItem::KIND_LABELS;
@endphp
<div class="max-w-7xl mx-auto space-y-6">

    <x-admin.page-header
        section="Data Sumber"
        title="Antrean Rekonsiliasi APKUK"
        state="live"
        description="SPS BOS adalah sumber live: laporan yang hilang bisa memang tidak berlaku, atau hanya sementara (sedang diedit/dipindahkan). Laporan yang hilang berstatus pending: tetap tersimpan, tidak dihitung sebagai laporan aktif, dan tidak dapat dipilih untuk WO baru. Pending tidak pernah diarsipkan otomatis berdasarkan lamanya hilang; laporan diarsipkan hanya oleh Admin atas konfirmasi pemilik data, atau pulih sendiri bila muncul kembali di sumber. WO yang sudah ada tidak pernah diubah."
    />

    @if(session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Hilang dari sumber</span>
            <div class="mt-2 text-3xl font-black {{ $summary['missing'] > 0 ? 'text-amber-600' : 'text-slate-800' }}">{{ $summary['missing'] }}</div>
            <div class="mt-2 text-[10px] text-slate-500">Pending; tidak dihitung aktif sampai diarsipkan atau muncul kembali</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Dugaan perubahan identitas</span>
            <div class="mt-2 text-3xl font-black {{ $summary['identity'] > 0 ? 'text-amber-600' : 'text-slate-800' }}">{{ $summary['identity'] }}</div>
            <div class="mt-2 text-[10px] text-slate-500">Identitas baru ditahan, belum disisipkan</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Sinkronisasi terakhir</span>
            @if($lastRun)
                <div class="mt-2"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold {{ $runClasses[$lastRun->status] ?? '' }}">{{ $lastRun->statusLabel() }}</span></div>
                <div class="mt-2 text-[10px] text-slate-500">#{{ $lastRun->id }} · {{ $wib($lastRun->started_at) }} WIB</div>
            @else
                <div class="mt-2 text-sm text-slate-500">Belum ada riwayat</div>
            @endif
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Snapshot valid terakhir</span>
            <div class="mt-2 text-3xl font-black text-slate-800">{{ $lastAccepted?->source_report_count ?? '—' }}</div>
            <div class="mt-2 text-[10px] text-slate-500">{{ $lastAccepted ? 'laporan · '.$wib($lastAccepted->started_at).' WIB' : 'Pembanding jumlah laporan berikutnya' }}</div>
        </div>
    </div>

    <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
            @foreach($tabs as $value => $label)
                <a href="{{ route('admin.apkuk-reconciliation.index', array_filter(['jenis' => $value])) }}"
                   class="rounded-lg px-3 py-1.5 text-xs font-semibold {{ ($kind ?? '') === $value ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
            @endforeach
            <span class="ml-auto text-xs text-slate-500">{{ $items->total() }} antrean terbuka</span>
        </div>

        @php
            $missingOnPage = collect($items->items())->where('kind', ApkukReconciliationItem::KIND_MISSING);
            $missingWithActiveWo = $missingOnPage->contains(fn ($missing) => array_filter($workOrders[$missing->apkuk_id] ?? [], fn ($ref) => $ref['active']) !== []);
        @endphp
        @if($missingOnPage->isNotEmpty())
            <form id="bulk-confirm" method="POST" action="{{ route('admin.apkuk-reconciliation.resolve-many', array_filter(['jenis' => $kind])) }}" class="flex flex-col lg:flex-row lg:items-end gap-3 border-b border-slate-200 bg-amber-50/40 px-4 py-3">
                @csrf
                <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 lg:pb-2">
                    <input type="checkbox" data-bulk-toggle> Pilih semua laporan hilang di halaman ini ({{ $missingOnPage->count() }})
                </label>
                <div class="flex-1">
                    <label for="bulk_owner" class="block text-xs font-semibold text-slate-600">Konfirmasi pemilik data SPS BOS (wajib)</label>
                    <input id="bulk_owner" type="text" name="owner_confirmation" required minlength="{{ ApkukReconciliationQueue::MIN_OWNER_CONFIRMATION }}" maxlength="{{ ApkukReconciliationQueue::MAX_OWNER_CONFIRMATION }}"
                           placeholder="Contoh: Nama, Pemilik SPS BOS — pesan WA 28/10 10.15" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="flex-1">
                    <label for="bulk_reason" class="block text-xs font-semibold text-slate-600">Alasan pengarsipan (dicatat di setiap antrean atas nama Anda)</label>
                    <input id="bulk_reason" type="text" name="reason" required minlength="{{ ApkukReconciliationQueue::MIN_REASON }}" maxlength="{{ ApkukReconciliationQueue::MAX_REASON }}"
                           placeholder="Contoh: laporan ganda/lama sudah tidak berlaku" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                @if($missingWithActiveWo)
                    <label class="flex items-start gap-2 text-xs text-red-700 lg:pb-2">
                        <input type="checkbox" name="acknowledge_active_wo" value="1" class="mt-0.5">
                        <span>Termasuk laporan dengan WO aktif; WO tidak diubah.</span>
                    </label>
                @endif
                <button type="submit" class="rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700">Arsipkan yang dipilih</button>
            </form>
        @endif

        @forelse($items as $item)
            @php
                $apkuk = $item->apkuk_id ? $apkuks->get($item->apkuk_id) : null;
                $refs = $item->apkuk_id ? ($workOrders[$item->apkuk_id] ?? []) : [];
                $activeRefs = array_filter($refs, fn ($ref) => $ref['active']);
                $record = $item->source_record ?? [];
                $candidates = $item->identityCandidates();
            @endphp
            <article class="border-b border-slate-100 p-4 last:border-b-0" id="antrean-{{ $item->id }}">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    @if($item->kind === ApkukReconciliationItem::KIND_MISSING)
                        <input type="checkbox" name="item_ids[]" value="{{ $item->id }}" form="bulk-confirm" data-bulk-item aria-label="Pilih antrean #{{ $item->id }} untuk pengarsipan massal">
                    @endif
                    <span class="font-bold text-slate-900">#{{ $item->id }}</span>
                    <span class="rounded-full border px-2 py-0.5 font-semibold {{ $item->kind === ApkukReconciliationItem::KIND_MISSING ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-violet-200 bg-violet-50 text-violet-700' }}">{{ $item->kindLabel() }}</span>
                    <span class="text-slate-500">Terdeteksi {{ $wib($item->first_detected_at) }} WIB · terlihat {{ $item->detection_count }}× · terakhir {{ $wib($item->last_detected_at) }} WIB</span>
                </div>

                <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="text-sm">
                        @if($item->kind === ApkukReconciliationItem::KIND_MISSING)
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Laporan di PostgreSQL (tidak ada di sumber terbaru)</p>
                            @if($apkuk)
                                <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-slate-700">
                                    <dt class="text-slate-500">ID AppSheet</dt><dd>{{ $apkuk->source_id ?? '—' }}</dd>
                                    <dt class="text-slate-500">No. tiang</dt><dd class="font-semibold">{{ $apkuk->no_tiang ?? '—' }}</dd>
                                    <dt class="text-slate-500">Tanggal</dt><dd>{{ $apkuk->tanggal?->format('d/m/Y') ?? '—' }}</dd>
                                    <dt class="text-slate-500">Penyulang</dt><dd>{{ $apkuk->penyulang ?? '—' }}</dd>
                                    <dt class="text-slate-500">Status · kurang</dt><dd>{{ $apkuk->status ?? '—' }} · {{ $apkuk->kurang_bh ?? '—' }}</dd>
                                    <dt class="text-slate-500">Baris sheet terakhir</dt><dd>{{ $apkuk->source_row_number ?? '—' }} (apkuk #{{ $apkuk->id }})</dd>
                                </dl>
                            @endif
                        @else
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Identitas baru di sumber (ditahan)</p>
                            <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-slate-700">
                                <dt class="text-slate-500">ID AppSheet</dt><dd>{{ $record['source_id'] ?? '—' }}</dd>
                                <dt class="text-slate-500">No. tiang baru</dt><dd class="font-semibold">{{ $record['no_tiang'] ?? '—' }}</dd>
                                <dt class="text-slate-500">Tanggal</dt><dd>{{ $record['tanggal'] ?? '—' }}</dd>
                                <dt class="text-slate-500">Penyulang</dt><dd>{{ $record['penyulang'] ?? '—' }}</dd>
                                <dt class="text-slate-500">Status · kurang</dt><dd>{{ $record['status'] ?? '—' }} · {{ $record['kurang_bh'] ?? '—' }}</dd>
                                <dt class="text-slate-500">Baris sheet</dt><dd>{{ $record['source_row_number'] ?? '—' }}</dd>
                            </dl>
                            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Laporan lama ber-ID sama yang hilang</p>
                            <ul class="mt-1 space-y-0.5 text-xs text-slate-700">
                                @foreach($candidates as $candidateId)
                                    @php($old = $apkuks->get($candidateId))
                                    <li>apkuk #{{ $candidateId }} · tiang <span class="font-semibold">{{ $old?->no_tiang ?? '—' }}</span> · {{ $old?->tanggal?->format('d/m/Y') ?? '—' }} · {{ $old?->status ?? '—' }}
                                        @foreach($workOrders[$candidateId] ?? [] as $ref)
                                            · <span class="{{ $ref['active'] ? 'font-semibold text-red-700' : 'text-slate-500' }}">{{ $ref['code'] }} ({{ $ref['status'] }})</span>
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($refs !== [])
                            <p class="mt-2 text-xs text-slate-600">WO yang merujuk:
                                @foreach($refs as $ref)
                                    <span class="{{ $ref['active'] ? 'font-semibold text-red-700' : 'text-slate-500' }}">{{ $ref['code'] }} ({{ $ref['status'] }})</span>@if(! $loop->last), @endif
                                @endforeach
                            </p>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('admin.apkuk-reconciliation.resolve', array_filter(['item' => $item->id, 'jenis' => $kind])) }}" class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                        @csrf
                        @if($item->kind === ApkukReconciliationItem::KIND_IDENTITY_CHANGE && count($candidates) > 1)
                            <label class="block text-xs font-semibold text-slate-600" for="apkuk_id_{{ $item->id }}">Laporan lama yang sama (bila laporan sama)</label>
                            <select id="apkuk_id_{{ $item->id }}" name="apkuk_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach($candidates as $candidateId)
                                    <option value="{{ $candidateId }}">apkuk #{{ $candidateId }} · {{ $apkuks->get($candidateId)?->no_tiang }}</option>
                                @endforeach
                            </select>
                        @endif
                        <label class="block text-xs font-semibold text-slate-600" for="reason_{{ $item->id }}">Alasan keputusan (wajib, tercatat atas nama Anda)</label>
                        <input id="reason_{{ $item->id }}" type="text" name="reason" required minlength="{{ ApkukReconciliationQueue::MIN_REASON }}" maxlength="{{ ApkukReconciliationQueue::MAX_REASON }}"
                               placeholder="Contoh: laporan ganda/lama sudah tidak berlaku" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @if($item->kind === ApkukReconciliationItem::KIND_MISSING)
                            <label class="block text-xs font-semibold text-slate-600" for="owner_{{ $item->id }}">Konfirmasi pemilik data SPS BOS (wajib untuk pengarsipan)</label>
                            <input id="owner_{{ $item->id }}" type="text" name="owner_confirmation" required minlength="{{ ApkukReconciliationQueue::MIN_OWNER_CONFIRMATION }}" maxlength="{{ ApkukReconciliationQueue::MAX_OWNER_CONFIRMATION }}"
                                   placeholder="Contoh: Nama, Pemilik SPS BOS — pesan WA 28/10 10.15" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @if($activeRefs !== [])
                                <label class="flex items-start gap-2 text-xs text-red-700">
                                    <input type="checkbox" name="acknowledge_active_wo" value="1" class="mt-0.5">
                                    <span>Saya memahami ada WO aktif yang merujuk laporan ini. WO tidak diubah.</span>
                                </label>
                            @endif
                            <button type="submit" name="decision" value="{{ ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED }}" class="rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700">Arsipkan (tidak berlaku, dikonfirmasi pemilik data)</button>
                        @else
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" name="decision" value="{{ ApkukReconciliationQueue::DECISION_CONFIRM_IDENTITY }}" class="rounded-lg bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-700">Laporan sama — perbarui identitas</button>
                                <button type="submit" name="decision" value="{{ ApkukReconciliationQueue::DECISION_INSERT_AS_NEW }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">Laporan berbeda — simpan sebagai baru</button>
                            </div>
                        @endif
                    </form>
                </div>
            </article>
        @empty
            <p class="p-6 text-center text-sm text-slate-500">Tidak ada antrean rekonsiliasi terbuka. Semua laporan aktif ada di snapshot sumber terakhir.</p>
        @endforelse

        @if($items->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $items->links() }}</div>
        @endif
    </section>

    <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <h2 class="px-4 pt-4 font-semibold text-slate-900">Riwayat apkuk:sync</h2>
        <table class="mt-2 min-w-full text-xs">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Mulai (WIB)</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Laporan sumber</th><th class="px-4 py-2">Baru</th><th class="px-4 py-2">Diperbarui</th><th class="px-4 py-2">Muncul kembali</th><th class="px-4 py-2">Pending</th><th class="px-4 py-2">Keterangan</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse($runs as $run)
                    <tr>
                        <td class="px-4 py-2">{{ $run->id }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $wib($run->started_at) }}</td>
                        <td class="px-4 py-2"><span class="inline-flex rounded-full border px-2 py-0.5 font-semibold {{ $runClasses[$run->status] ?? '' }}">{{ $run->statusLabel() }}</span></td>
                        <td class="px-4 py-2">{{ $run->source_report_count ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $run->counts['inserted'] ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $run->counts['updated'] ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $run->counts['returned'] ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $run->counts['pending_open'] ?? '—' }}</td>
                        <td class="px-4 py-2 max-w-md">{{ $run->failure_stage ? (ApkukSyncRun::STAGE_LABELS[$run->failure_stage] ?? $run->failure_stage).': '.\Illuminate\Support\Str::limit($run->failure_reason, 160) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-4 text-center text-slate-500">Belum ada riwayat sinkronisasi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <h2 class="px-4 pt-4 font-semibold text-slate-900">Penyelesaian terakhir</h2>
        <table class="mt-2 min-w-full text-xs">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Jenis</th><th class="px-4 py-2">Laporan</th><th class="px-4 py-2">Keputusan</th><th class="px-4 py-2">Oleh</th><th class="px-4 py-2">Konfirmasi pemilik data</th><th class="px-4 py-2">Alasan</th><th class="px-4 py-2">Waktu (WIB)</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse($resolved as $done)
                    <tr>
                        <td class="px-4 py-2">{{ $done->id }}</td>
                        <td class="px-4 py-2">{{ $done->kindLabel() }}</td>
                        <td class="px-4 py-2">ID {{ $done->source_id ?? '—' }} · {{ $done->no_tiang ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $done->resolutionLabel() }}</td>
                        <td class="px-4 py-2">
                            @if ($done->resolvedBy)
                                {{ $done->resolvedBy->name }} ({{ $done->resolvedBy->username }}){{ $done->reconciliation_id ? ' via apkuk:reconcile #'.$done->reconciliation_id : '' }}
                            @elseif ($done->resolved_run_id)
                                Otomatis (sync #{{ $done->resolved_run_id }})
                            @else
                                Sistem (penyelesaian terkait)
                            @endif
                        </td>
                        <td class="px-4 py-2 max-w-xs">{{ $done->owner_confirmation ?? '—' }}</td>
                        <td class="px-4 py-2 max-w-md">{{ \Illuminate\Support\Str::limit($done->resolution_reason, 160) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $wib($done->resolved_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-4 text-center text-slate-500">Belum ada antrean yang diselesaikan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
<script>
    document.querySelector('[data-bulk-toggle]')?.addEventListener('change', (event) => {
        document.querySelectorAll('[data-bulk-item]').forEach((box) => { box.checked = event.target.checked; });
    });
</script>
@endsection
