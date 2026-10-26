@extends('layouts.admin')

@section('title', 'Posko '.$posko->nama_posko)

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        parent="Master Data"
        :parent-url="route('admin.master-data.index', ['tab' => 'posko'])"
        :title="$posko->nama_posko"
        state="live"
        description="Detail master Posko. Relasi Penyulang bersifat many-to-many dan tidak menjadi dasar kepemilikan aset."
    >
        <x-slot:actions>
            <a href="{{ route('admin.pasila.show', $posko) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Konteks PASILA</a>
            <a href="{{ route('admin.work-orders.index', ['posko_id' => $posko->id]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">WO APKUK Posko</a>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel title="Identitas">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode">{{ $posko->kode_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Nama">{{ $posko->nama_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Status">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="WO total / aktif">
                @if ($load->failed('work_orders'))
                    <span class="text-red-700">Gagal dimuat</span>
                @else
                    {{ $workOrders['total'] }} / {{ $workOrders['active'] }}
                @endif
            </x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel title="Penyulang terkait">
            @if ($load->failed('penyulangs'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($penyulangs as $penyulang)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-semibold text-slate-800">{{ $penyulang->nama_penyulang }} <span class="text-[10px] font-normal text-slate-500">{{ $penyulang->kode_penyulang }}</span></span>
                            <a href="{{ route('admin.master-data.penyulang.show', $penyulang) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">Belum ada Penyulang terhubung.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
        <x-admin.panel title="Akun tim lapangan">
            @if ($load->failed('teams'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($teams as $team)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-semibold text-slate-800">{{ $team->name }} <span class="text-[10px] font-normal text-slate-500">{{ $team->code }} · {{ $team->role }}</span></span>
                            <span class="text-xs text-slate-500">{{ $team->active_personnel_count }} petugas aktif</span>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">Belum ada akun tim untuk Posko ini.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
    </div>
</div>
@endsection
