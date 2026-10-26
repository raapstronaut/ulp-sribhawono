@extends('layouts.admin')

@section('title', 'Special Case')

@section('content')
@php
    $dimensionLabel = \App\Http\Controllers\Admin\SpecialCaseController::DIMENSIONS[$filters['dimension']];
    $maxTotal = (int) ($groups->max('total') ?? 0);
    $fromLabel = \Illuminate\Support\Carbon::parse($range['from'])->format('d/m/Y');
    $toLabel = \Illuminate\Support\Carbon::parse($range['to'])->format('d/m/Y');
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Kontrol"
        title="Special Case"
        state="partial"
        description="Bahan analisis frekuensi gangguan per Penyulang, peralatan, atau tiang dari data gangguan PLN pusat yang tersinkron."
    />

    <x-admin.notice tone="warning" title="Metode Special Case belum final:">
        Tidak ada aturan keras seperti lebih dari tiga trip per bulan. Periode, normalisasi, ambang, dan tindak lanjut masih TBD, sehingga tabel ini hanya menampilkan frekuensi mentah. Halaman ini tidak menandai special case dan tidak membuat P0 di luar PASILA.
    </x-admin.notice>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.stat-card label="Gangguan dalam periode" :value="$summary['total']" :hint="$fromLabel.' – '.$toLabel" />
            <x-admin.stat-card :label="'Tanpa '.$dimensionLabel" :value="$summary['missing_dimension']" hint="Kolom sumber kosong; tidak ditebak" :tone="$summary['missing_dimension'] > 0 ? 'amber' : 'slate'" />
            <x-admin.stat-card label="Tanpa tanggal kejadian" :value="$summary['without_date']" hint="Seluruh data; tidak masuk analisis periode" :tone="$summary['without_date'] > 0 ? 'amber' : 'slate'" />
            <x-admin.stat-card label="Sinkron terakhir" :value="$summary['last_synced_at'] ? \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->timezone(\App\Services\ShiftService::TIMEZONE)->format('d/m H:i') : null" hint="gangguan:sync dari Google Sheets (WIB)" unavailable-label="Belum pernah" />
        </div>
    @endif

    <x-admin.panel flush :title="'Frekuensi gangguan per '.$dimensionLabel" description="Diurutkan dari jumlah gangguan terbanyak. Klik Detail untuk melihat daftar kejadian.">
        <x-admin.filter-bar :action="route('admin.special-case.index')" :reset-url="route('admin.special-case.index')" :active="$hasFilter">
            <x-admin.filter-select name="period" label="Periode" :options="\App\Http\Controllers\Admin\SpecialCaseController::PERIODS" :value="$filters['period']" :allow-empty="false" />
            <x-admin.filter-select name="dimension" label="Kelompokkan per" :options="\App\Http\Controllers\Admin\SpecialCaseController::DIMENSIONS" :value="$filters['dimension']" :allow-empty="false" />
            <x-admin.filter-input name="q" :label="'Cari '.$dimensionLabel" :value="$filters['q']" placeholder="Nama atau kode" />
        </x-admin.filter-bar>

        @if ($load->failed('groups'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[760px]">
                <x-slot:head>
                    <th class="px-4 py-3">{{ $dimensionLabel }}</th>
                    <th class="px-4 py-3 text-right">Jumlah</th>
                    <th class="w-1/4 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                    <th class="px-4 py-3 text-right">Total padam (menit)</th>
                    <th class="px-4 py-3">Kejadian terakhir</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($groups as $group)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ filled($group->group_key) ? $group->group_key : '(kosong)' }}</td>
                        <td class="px-4 py-3 text-right font-bold text-slate-800">{{ number_format($group->total, 0, ',', '.') }}</td>
                        <td class="px-4 py-3"><x-admin.bar :value="(int) $group->total" :max="$maxTotal" :label="$group->total.' gangguan'" /></td>
                        <td class="px-4 py-3 text-right text-xs">{{ $group->total_padam !== null ? number_format((float) $group->total_padam, 0, ',', '.') : '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $group->last_at ? \Illuminate\Support\Carbon::parse($group->last_at)->format('d/m/Y') : '-' }}</td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('admin.special-case.show', ['dimension' => $filters['dimension'], 'key' => (string) $group->group_key, 'period' => $filters['period']]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-admin.empty-state title="Tidak ada gangguan pada periode ini." description="Belum ada data gangguan tersinkron pada rentang tanggal yang dipilih, atau tidak ada yang cocok dengan pencarian." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$groups" :label="'kelompok '.$dimensionLabel" />
        @endif
    </x-admin.panel>
</div>
@endsection
