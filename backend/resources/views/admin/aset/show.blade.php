@extends('layouts.admin')

@section('title', 'Detail Aset (Sumber)')

@section('content')
<div class="mx-auto max-w-7xl space-y-3 pb-10">
    <div class="pln-workspace-panel">
        <div class="pln-panel-title">DATA JARINGAN / DETAIL ASET</div>
        <div class="pln-panel-body">
            <a class="text-xs font-semibold text-blue-700 underline" href="{{ route('admin.aset.index') }}">Kembali ke katalog</a>
            <h1 class="mt-2 text-xl font-bold text-slate-900">{{ $asset->nama_aset_raw ?: 'Aset tanpa nama' }}</h1>
            <p class="text-sm text-slate-600">ID sumber: {{ $asset->id_aset_raw ?: 'Belum tersedia' }} · {{ $asset->jenis_aset_raw ?: 'Jenis belum diketahui' }}</p>
            <div class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950">
                <strong>Identitas belum diverifikasi.</strong> Ini satu baris staging ASSET_RAW (nomor {{ $asset->source_row_number ?? '-' }}), bukan master aset canonical. ID internal dapat berubah setelah impor ulang; histori permanen belum boleh dikaitkan otomatis.
                @if ($sameIdCount > 1) <strong>ID sumber yang sama ditemukan pada {{ $sameIdCount }} baris.</strong> @endif
            </div>
        </div>
    </div>

    <div class="pln-workspace-panel" id="pln-asset-tabs">
        <div class="pln-panel-title">DETAIL DATA</div>
        <div class="pln-subtabs" role="tablist" aria-label="Informasi detail aset">
            @foreach ([
                'informasi' => 'Info Aset',
                'teknis' => 'Teknis',
                'histori' => 'Histori',
                'pekerjaan' => 'WO & Inspeksi',
                'foto' => 'Foto',
            ] as $tabId => $tabLabel)
                <button type="button" role="tab" id="tab-{{ $tabId }}" class="pln-subtab" data-asset-tab="{{ $tabId }}"
                    aria-controls="panel-{{ $tabId }}" aria-selected="{{ $tabId === 'informasi' ? 'true' : 'false' }}" tabindex="{{ $tabId === 'informasi' ? '0' : '-1' }}">{{ $tabLabel }}</button>
            @endforeach
        </div>

        <section id="panel-informasi" class="pln-tabpanel p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-informasi" tabindex="0">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Informasi Aset (data sumber)</h2>
            <dl class="pln-detail-dl">
                @foreach ([
                    'ID Aset' => $asset->id_aset_raw,
                    'Nama Aset' => $asset->nama_aset_raw,
                    'Jenis Aset' => $asset->jenis_aset_raw,
                    'Penyulang sumber' => $asset->penyulang_raw,
                    'Status sumber' => $asset->status_raw,
                    'Section (belum terverifikasi)' => $asset->section_raw,
                    'Posko (belum terverifikasi)' => $asset->posko_raw,
                    'Latitude' => $asset->latitude_raw,
                    'Longitude' => $asset->longitude_raw,
                ] as $label => $value)
                    <dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : 'Belum tersedia' }}</dd>
                @endforeach
            </dl>
        </section>

        <section id="panel-teknis" class="pln-tabpanel p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-teknis" tabindex="0">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Spesifikasi Teknis</h2>
            <dl class="pln-detail-dl">
                @foreach (['Tinggi / jenis tiang', 'Jenis konduktor', 'Penampang konduktor', 'ID trafo', 'Kapasitas trafo', 'Tanggal pemasangan'] as $label)
                    <dt>{{ $label }}</dt><dd><span class="text-amber-800">Belum tersedia</span></dd>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-600">ASSET_RAW belum menyediakan kolom di atas. Data master resmi dan verifikasi teknis diperlukan sebelum ditampilkan.</p>
        </section>

        <section id="panel-histori" class="pln-tabpanel p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-histori" tabindex="0">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Histori Pemasangan & Perubahan Aset</h2>
            <div class="rounded border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">Belum ada histori pemasangan yang dapat dibuktikan untuk aset staging ini. Tanggal impor data <strong>bukan</strong> tanggal pemasangan. Riwayat teknis akan ditampilkan setelah identitas aset dipadankan secara resmi.</div>
        </section>

        <section id="panel-pekerjaan" class="pln-tabpanel p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-pekerjaan" tabindex="0">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Work Order & INSPEKSI Terkait</h2>
            <p class="text-sm text-slate-700">Pencocokan histori WO dan INSPEKSI ke ID aset staging ini <strong>belum diaktifkan</strong>, karena belum ada identitas aset canonical yang disahkan. Histori operasional asli tetap tersedia di modul masing-masing.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a class="pln-btn" href="{{ route('admin.work-orders.index') }}">Daftar WO</a>
                <a class="pln-btn" href="{{ route('admin.inspeksi.index') }}">Daftar INSPEKSI</a>
            </div>
        </section>

        <section id="panel-foto" class="pln-tabpanel p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-foto" tabindex="0">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Dokumentasi Foto</h2>
            <p class="text-sm text-slate-700">{{ filled($asset->foto_raw) ? 'Referensi foto sumber tercatat, tetapi belum diverifikasi untuk ditampilkan sebagai media.' : 'Belum ada referensi foto dari sumber.' }}</p>
        </section>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (() => {
        const root = document.getElementById('pln-asset-tabs');
        if (!root) return;
        const tabs = [...root.querySelectorAll('[data-asset-tab]')];
        const panels = [...root.querySelectorAll('.pln-tabpanel')];
        const valid = new Set(tabs.map(tab => tab.dataset.assetTab));
        function activate(key, writeHash = true) {
            if (!valid.has(key)) key = 'informasi';
            tabs.forEach(tab => {
                const selected = tab.dataset.assetTab === key;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
            });
            panels.forEach(panel => { panel.hidden = panel.id !== 'panel-' + key; });
            if (writeHash && history.replaceState) history.replaceState(null, '', '#' + key);
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activate(tab.dataset.assetTab));
            tab.addEventListener('keydown', event => {
                const move = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
                if (!move && event.key !== 'Home' && event.key !== 'End') return;
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + move + tabs.length) % tabs.length;
                tabs[next].focus();
                activate(tabs[next].dataset.assetTab);
            });
        });
        window.addEventListener('hashchange', () => activate(location.hash.slice(1), false));
        activate(location.hash.slice(1), false);
    })();
</script>
@endsection
