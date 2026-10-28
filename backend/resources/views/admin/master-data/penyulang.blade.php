@extends('layouts.admin')

@section('title', 'Penyulang '.$penyulang->nama_penyulang)

@section('content')
@php
    $teamLabels = ['POSKO' => 'POSKO', 'HARSUS' => 'HARSUS', 'ROW' => 'ROW'];
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        parent="Master Data"
        :parent-url="route('admin.master-data.index', ['tab' => 'penyulang'])"
        :title="$penyulang->nama_penyulang"
        state="live"
        description="Detail master Penyulang beserta jumlah data sumber yang namanya cocok."
    >
        <x-slot:actions>
            <a href="{{ route('admin.special-case.index', ['dimension' => 'penyulang', 'q' => $penyulang->nama_penyulang]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Frekuensi gangguan</a>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel title="Identitas">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode">{{ $penyulang->kode_penyulang }}</x-admin.detail-item>
            <x-admin.detail-item label="Nama">{{ $penyulang->nama_penyulang }}</x-admin.detail-item>
            <x-admin.detail-item label="Gardu induk">{{ $penyulang->gardu_induk }}</x-admin.detail-item>
            <x-admin.detail-item label="Status">{{ $penyulang->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <x-admin.notice title="Pencocokan teks:">
        Tabel keypoints, apkuks, dan gangguans menyimpan nama penyulang sebagai teks, bukan relasi canonical. Jumlah di bawah dicocokkan dengan {{ implode(' / ', $matchedNames) }} (huruf besar, spasi tepi dihapus), sehingga baris dengan penulisan berbeda tidak terhitung.
    </x-admin.notice>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-admin.stat-card label="Keypoint" :value="$sourceCounts['keypoints']" hint="Tabel keypoints (SPS BOS)" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="APKUK aktif sumber" :value="$sourceCounts['apkuks']" hint="Ada di snapshot SPS BOS terbaru; pending dan histori tidak dihitung" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Gangguan (semua periode)" :value="$sourceCounts['gangguans']" hint="Tabel gangguans" unavailable-label="Gagal dimuat" />
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel title="Posko terkait" description="Satu Penyulang dapat terkait beberapa Posko.">
            @if ($load->failed('poskos'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($poskos as $posko)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-semibold text-slate-800">{{ $posko->nama_posko }}</span>
                            <a href="{{ route('admin.master-data.posko.show', $posko) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">Belum ada Posko terhubung.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
        <x-admin.panel title="WO per tim" description="Berdasarkan relasi penyulang_id pada work_orders.">
            @if ($load->failed('work_orders'))
                <x-admin.error-state :inset="false" />
            @else
                <dl class="grid grid-cols-3 gap-3 text-center">
                    @foreach ($teamLabels as $teamKey => $teamLabel)
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-[10px] font-bold uppercase text-slate-500">{{ $teamLabel }}</dt>
                            <dd class="text-2xl font-black text-slate-900">{{ $workOrders[$teamKey] ?? 0 }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-admin.panel>
    </div>
</div>
@endsection
