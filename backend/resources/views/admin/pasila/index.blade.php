@extends('layouts.admin')

@section('title', 'PASILA')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="PASILA Monitor"
        state="partial"
        description="PASILA dibuat otomatis pada awal Shift A, B, dan C untuk setiap Posko. P0 POSKO wajib terkait PASILA pada Section dan Posko yang sama."
    >
        <x-slot:actions>
            <x-admin.unavailable-action label="Buat PASILA" reason="Pembuatan PASILA manual membutuhkan tabel sesi PASILA, shift instance, dan Section canonical yang belum dibangun." />
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice tone="warning" title="Domain PASILA belum dibangun:">
        Generator PASILA awal shift, sesi PASILA, temuan per titik, dan P0 terkait belum memiliki tabel di database. Halaman ini hanya menampilkan konteks shift dari master Posko serta WO APKUK nyata pada shift yang sama, dan tidak menampilkan sesi PASILA.
    </x-admin.notice>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat-card label="Posko aktif" :value="$load->failed('poskos') ? null : $totals['poskos']" hint="Dari master poskos" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="WO APKUK shift" :value="$load->failed('work_orders') ? null : $totals['work_orders']" :hint="'Referensi target '.$totals['target'].' WO ('.$targetPerShift.' per Posko)'" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Sesi PASILA" hint="Sesi otomatis awal shift belum dibuat sistem" />
        <x-admin.stat-card label="P0 terkait PASILA" hint="Domain P0 belum tersedia" :href="route('admin.p0.index')" />
    </div>

    <x-admin.panel flush :title="'Shift '.$context['shift']['code'].' · '.$context['shift']['name'].' — '.\Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y')" :description="$context['shift']['time'].($context['is_current'] ? ' · shift berjalan' : ' · bukan shift berjalan')">
        <x-admin.filter-bar :action="route('admin.pasila.index')" :reset-url="route('admin.pasila.index')" :active="$hasFilter">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal kerja" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko aktif" />
        </x-admin.filter-bar>

        @if ($load->failed('poskos', 'work_orders', 'reschedules'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[900px]">
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th>
                    <th class="px-4 py-3">WO APKUK (realisasi / target)</th>
                    <th class="px-4 py-3">Reschedule diajukan</th>
                    <th class="px-4 py-3">Sesi PASILA</th>
                    <th class="px-4 py-3">P0 terkait</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($rows as $row)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-900">{{ $row['posko']->nama_posko }}</div>
                            <div class="text-[10px] text-slate-500">{{ $row['posko']->kode_posko }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="w-14 shrink-0 text-xs font-bold text-slate-800">{{ $row['selesai'] }} / {{ $targetPerShift }}</span>
                                <x-admin.bar :value="$row['selesai']" :max="$targetPerShift" :label="$row['selesai'].' selesai dari target '.$targetPerShift" />
                            </div>
                            <div class="mt-1 text-[10px] text-slate-500">{{ $row['total'] }} WO tercatat · {{ $row['aktif'] }} aktif</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($row['reschedule'] > 0)
                                <x-admin.status-badge tone="amber">{{ $row['reschedule'] }} menunggu</x-admin.status-badge>
                            @else
                                <span class="text-xs text-slate-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-[11px] text-slate-400">Belum tersedia</td>
                        <td class="px-4 py-3 text-[11px] text-slate-400">Belum tersedia</td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('admin.pasila.show', ['posko' => $row['posko'], 'work_date' => $context['work_date'], 'shift_code' => $context['shift_code']]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-admin.empty-state title="Belum ada Posko aktif." description="Master Posko aktif kosong atau tidak cocok dengan filter." />
                        </td>
                    </tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-admin.unavailable-panel
            title="Rekomendasi Section"
            description="Section PASILA direkomendasikan dari prioritas gangguan, kondisi aset, histori kunjungan, dan rotasi."
            :requirements="['Section canonical tervalidasi', 'Bobot prioritas dan rotasi (TBD)']"
        />
        <x-admin.unavailable-panel
            title="Temuan per Titik"
            description="Satu temuan memiliki jenis/kode, aset atau koordinat, foto, GPS, waktu, dan petugas sendiri."
            :requirements="['Tabel pasila_sessions dan pasila_findings', 'Kode temuan resmi di reference_codes']"
        />
        <x-admin.unavailable-panel
            title="P0 dalam PASILA"
            description="Minimal satu P0 per shift POSKO. P0 SYSTEM perlu ACC Admin; bila ditolak, Admin wajib membuat P0 manual pengganti dalam PASILA yang sama."
            :requirements="['Domain P0 terikat PASILA', 'Skema detail P0 (menunggu konfirmasi atasan)']"
        />
    </div>
</div>
@endsection
