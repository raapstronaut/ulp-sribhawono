@extends('layouts.admin')

@section('content')
<div class="mx-auto max-w-[1600px] space-y-4" id="maps-page">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Peta Jaringan</h1>
            <p class="mt-1 text-sm text-slate-500">Monitoring Keypoint, APKUK dan rekap Gangguan — ULP Sribawono</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button id="maps-fit" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Lihat Semua Titik</button>
            <button id="maps-refresh" type="button" class="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800">Segarkan Data</button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan lokasi">
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"><p class="text-xs text-slate-500">Keypoint terpetakan</p><p id="maps-stat-keypoint" class="mt-1 text-xl font-bold text-blue-700">—</p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"><p class="text-xs text-slate-500">APKUK terpetakan</p><p id="maps-stat-apkuk" class="mt-1 text-xl font-bold text-violet-700">—</p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"><p class="text-xs text-slate-500">Gangguan (tanpa GPS)</p><p id="maps-stat-gangguan" class="mt-1 text-xl font-bold text-rose-700">—</p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"><p class="text-xs text-slate-500">Marker ditampilkan</p><p id="maps-stat-visible" class="mt-1 text-xl font-bold text-slate-800">—</p></div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="xl:col-span-2">
                <label for="maps-search" class="mb-1 block text-xs font-bold text-slate-600">Cari Nama / No. Tiang / ID</label>
                <input id="maps-search" type="search" autocomplete="off" placeholder="Contoh: BRAJADEWA / F.REOG.001" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
            </div>
            <div>
                <label for="maps-penyulang-filter" class="mb-1 block text-xs font-bold text-slate-600">Penyulang</label>
                <select id="maps-penyulang-filter" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Semua Penyulang</option></select>
            </div>
            <div>
                <label for="maps-jenis-filter" class="mb-1 block text-xs font-bold text-slate-600">Jenis Keypoint</label>
                <select id="maps-jenis-filter" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Semua Jenis</option></select>
            </div>
            <div class="flex items-end">
                <button id="maps-reset-filter" type="button" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-blue-50">Reset Semua Filter</button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-700">
                <label class="flex cursor-pointer items-center gap-2"><input id="maps-layer-keypoint" type="checkbox" checked class="accent-blue-700"> <span>Keypoint</span></label>
                <label class="flex cursor-pointer items-center gap-2"><input id="maps-layer-apkuk" type="checkbox" class="accent-violet-700"> <span>APKUK</span></label>
            </div>
            <p id="maps-filter-status" class="text-xs font-medium text-slate-500" role="status" aria-live="polite">Memuat data...</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="relative min-w-0">
                <div id="admin-network-map" data-endpoint="{{ route('admin.maps.data') }}" class="h-[560px] w-full" aria-label="Peta lokasi jaringan distribusi"></div>
                <p id="maps-updated" class="absolute bottom-3 left-3 z-[400] max-w-[60%] rounded-md bg-white/95 px-2 py-1 text-[11px] text-slate-600 shadow">Memuat...</p>
            </div>

            <div class="flex min-h-[420px] flex-col border-t border-slate-200 bg-white lg:h-[560px] lg:border-l lg:border-t-0">
                <div class="flex border-b border-slate-200" role="tablist" aria-label="Jenis daftar data">
                    <button id="maps-tab-locations" type="button" role="tab" aria-selected="true" class="flex-1 border-b-2 border-blue-600 px-3 py-3 text-sm font-bold text-blue-700">Titik di Peta</button>
                    <button id="maps-tab-gangguan" type="button" role="tab" aria-selected="false" class="flex-1 border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-slate-600">Daftar Gangguan</button>
                    <button id="maps-tab-issues" type="button" role="tab" aria-selected="false" class="flex-1 border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-slate-600">Koordinat Invalid</button>
                </div>
                <div class="border-b border-slate-100 px-3 py-2 text-xs text-slate-500" id="maps-list-caption">Klik nama untuk menuju lokasi dan membuka detail.</div>
                <div id="maps-results" class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3" role="list"></div>
                <button id="maps-load-more" type="button" class="hidden border-t border-slate-200 px-3 py-3 text-sm font-semibold text-blue-700 hover:bg-blue-50">Tampilkan Lebih Banyak</button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 px-4 py-3 text-xs text-slate-600">
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-blue-600 align-middle"></span>Recloser</span>
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-amber-500 align-middle"></span>LBSM</span>
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-green-600 align-middle"></span>FCO</span>
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-violet-600 align-middle"></span>APKUK</span>
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-pink-600 align-middle"></span>APKUK menunggu rekonsiliasi</span>
            <span><span class="mr-1 inline-block h-3 w-3 rounded-full bg-slate-500 align-middle"></span>Keypoint lain</span>
        </div>
        <div class="border-t border-slate-100 px-4 py-3 text-xs text-amber-800">
            <strong>Catatan data:</strong> Gangguan belum menyimpan koordinat, jadi hanya tampil sebagai daftar, bukan marker. APKUK yang tidak mempunyai koordinat atau sudah menjadi histori juga tidak ditampilkan sebagai marker. Titik dengan koordinat bernilai 0 atau di luar wilayah operasional ULP tidak dipetakan dan tidak memengaruhi batas tampilan peta; daftarnya ada di tab Koordinat Invalid, dan nilai aslinya di database dan sumber tidak diubah. APKUK yang tidak lagi ada di sumber terbaru tampil merah muda dengan tanda "menunggu rekonsiliasi" dan tidak dihitung sebagai APKUK aktif, sampai muncul kembali atau diarsipkan di <a href="{{ route('admin.apkuk-reconciliation.index') }}" class="font-semibold underline">Antrean Rekonsiliasi APKUK</a>.
        </div>
    </div>
</div>
@endsection
