@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto space-y-6 pb-12">
    <div class="flex items-center justify-between gap-4">
        <div>
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Pekerjaan / Work Order</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-1">Detail Work Order</h1>
            <p class="text-sm text-slate-500 mt-2">Detail pekerjaan berlaku untuk POSKO, HARSUS, dan ROW sesuai jenis WO.</p>
        </div>
        <a href="{{ route('admin.work-orders.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Kembali ke WO</a>
    </div>

    @if(!isset($wo) || !$wo)
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
            <h2 class="font-bold text-slate-800">Data Work Order belum tersedia</h2>
            <p class="text-sm text-slate-500 mt-3">Modul transaksi WO belum dihubungkan ke PostgreSQL. Tidak ada koordinat, foto, atau hasil pekerjaan demonstrasi yang digunakan sebagai data nyata.</p>
        </div>
    @else
        @php
            $woId = data_get($wo, 'id', '-');
            $woType = data_get($wo, 'work_type', data_get($wo, 'jenis_wo', '-'));
            $teamType = data_get($wo, 'team_type', '-');
            $woStatus = data_get($wo, 'status', '-');
            $assetCode = data_get($wo, 'asset.external_id', data_get($wo, 'asset_id', '-'));
            $feederName = data_get($wo, 'penyulang.nama_penyulang', '-');
            $sectionName = data_get($wo, 'section.name', '-');
            $poskoName = data_get($wo, 'posko.nama_posko', '-');
        @endphp
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div><div class="text-xs text-slate-500">ID WO</div><div class="font-semibold text-slate-900">{{ $woId }}</div></div>
                <div><div class="text-xs text-slate-500">Tim Pelaksana</div><div class="font-semibold text-slate-900">{{ $teamType }}</div></div>
                <div><div class="text-xs text-slate-500">Status</div><div class="font-semibold text-slate-900">{{ $woStatus }}</div></div>
                <div><div class="text-xs text-slate-500">Jenis Pekerjaan</div><div class="font-semibold text-slate-900">{{ $woType }}</div></div>
                <div><div class="text-xs text-slate-500">Aset</div><div class="font-semibold text-slate-900">{{ $assetCode }}</div></div>
                <div><div class="text-xs text-slate-500">Penyulang</div><div class="font-semibold text-slate-900">{{ $feederName }}</div></div>
                <div><div class="text-xs text-slate-500">Section</div><div class="font-semibold text-slate-900">{{ $sectionName }}</div></div>
                <div><div class="text-xs text-slate-500">Posko</div><div class="font-semibold text-slate-900">{{ $poskoName }}</div></div>
                <div><div class="text-xs text-slate-500">Jadwal</div><div class="font-semibold text-slate-900">{{ data_get($wo, 'due_at', '-') }}</div></div>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-900">Histori, Bukti dan Relasi Pekerjaan</h2>
            <p class="text-sm text-slate-500 mt-2">Data valid dari domain operasional akan ditampilkan di sini setelah tersedia. Foto lama tidak boleh ditimpa; eskalasi harus mempertahankan sumber dan child WO.</p>
        </div>
    @endif
</div>
@endsection
