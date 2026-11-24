@extends('layouts.admin')

@section('title', 'Katalog Aset Jaringan')

@section('content')
<div class="mx-auto max-w-7xl space-y-3 pb-10">
    <div class="pln-workspace-panel">
        <div class="pln-panel-title">DATA JARINGAN / KATALOG ASET</div>
        <div class="pln-panel-body">
            <h1 class="text-xl font-bold text-slate-900">Katalog Aset Jaringan</h1>
            <p class="mt-1 text-sm text-slate-600">Penelusuran data sumber ASSET_RAW. Bukan master aset yang sudah divalidasi.</p>
            <p class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
                <strong>Data parsial / staging.</strong> ID baris bisa berubah saat impor ulang. Informasi tanggal pemasangan, penampang, spesifikasi trafo, dan histori permanen belum tersedia. Status VALID berasal dari sumber dan bukan pengesahan master aset.
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.aset.index') }}" class="pln-workspace-panel" data-loading-form>
        <div class="pln-panel-title">FILTER PENCARIAN ASET</div>
        <div class="pln-panel-body">
            <div class="pln-filter-grid">
                <label><span class="pln-field-label">ID / Nama aset</span>
                    <input class="pln-field" type="search" name="q" maxlength="80" value="{{ $q }}" placeholder="ID aset atau nama aset" autocomplete="off">
                </label>
                <label><span class="pln-field-label">Jenis aset</span>
                    <select class="pln-field" name="jenis">
                        <option value="">Semua jenis</option>
                        @foreach (($options['jenis'] ?? []) as $option)
                            <option value="{{ $option }}" @selected(($filters['jenis'] ?? '') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="pln-field-label">Penyulang</span>
                    <select class="pln-field" name="penyulang">
                        <option value="">Semua penyulang</option>
                        @foreach (($options['penyulang'] ?? []) as $option)
                            <option value="{{ $option }}" @selected(($filters['penyulang'] ?? '') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="pln-field-label">Status sumber</span>
                    <select class="pln-field" name="status">
                        <option value="">Semua status</option>
                        @foreach (($options['status'] ?? []) as $option)
                            <option value="{{ $option }}" @selected(($filters['status'] ?? '') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
        <div class="pln-action-row">
            <button class="pln-btn pln-btn-primary" type="submit" >Search</button>
            <a class="pln-btn" href="{{ route('admin.aset.index') }}">Reset</a>
            <span class="ml-auto hidden text-xs text-slate-500 sm:inline">Sumber: ASSET_RAW</span>
        </div>
    </form>

    <div class="pln-workspace-panel">
        <div class="pln-panel-title">HASIL PENCARIAN</div>
        @unless($ready)
            <p class="p-4 text-sm text-amber-800">Tabel ASSET_RAW belum tersedia pada database ini. Tidak ada perubahan data dilakukan.</p>
        @else
            <p class="border-b border-slate-200 px-4 py-2 text-xs text-slate-600">{{ number_format($assets->total(), 0, ',', '.') }} baris sumber ditemukan</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[750px] text-left text-sm">
                    <thead class="text-xs uppercase"><tr>
                        <th class="px-4 py-3">ID Sumber</th>
                        <th class="px-4 py-3">Nama / Jenis</th>
                        <th class="px-4 py-3">Penyulang</th>
                        <th class="px-4 py-3">Status sumber</th>
                        <th class="px-4 py-3">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse($assets as $asset)
                            <tr class="border-b border-slate-100">
                                <td class="px-4 py-3 font-semibold">{{ $asset->id_aset_raw ?: 'Belum tersedia' }}</td>
                                <td class="px-4 py-3">{{ $asset->nama_aset_raw ?: 'Tanpa nama' }}<div class="text-xs text-slate-500">{{ $asset->jenis_aset_raw ?: 'Jenis belum tersedia' }}</div></td>
                                <td class="px-4 py-3">{{ $asset->penyulang_raw ?: 'Belum tersedia' }}</td>
                                <td class="px-4 py-3">{{ $asset->status_raw ?: 'Belum tersedia' }} <span class="block text-[10px] text-slate-500">Status sumber, bukan verifikasi</span></td>
                                <td class="px-4 py-3"><a class="font-semibold text-blue-700 underline" href="{{ route('admin.aset.show', $asset->id) }}">Buka detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-600">Tidak ada aset yang cocok dengan filter. Klik Reset untuk melihat seluruh sumber.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $assets->links() }}</div>
        @endunless
    </div>
</div>
@endsection
