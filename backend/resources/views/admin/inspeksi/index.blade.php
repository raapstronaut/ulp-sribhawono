@extends('layouts.admin')

@section('title', 'Inspeksi')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $resultFilters = \App\Http\Controllers\Admin\InspectionController::RESULT_FILTERS;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" title="Inspeksi" state="live"
        description="Kunjungan tim INSPEKSI beserta temuan per titik. Hasil 'Tidak Ada Temuan' dicatat jujur dan tidak dihitung sebagai temuan. Temuan wajib divalidasi Admin sebelum menjadi dasar tindakan.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.inspeksi.create')">Catat inspeksi</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice title="Belum otomatis:">
        Ranking/rotasi area kunjungan dan rekomendasi tim penerima temuan masih menunggu formula prioritas (TBD). Saran tim diisi petugas atau Admin; Admin menentukan tindak lanjut saat validasi.
    </x-admin.notice>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <x-admin.stat-card label="Kunjungan hari ini" :value="$summary['visits_today']" />
            <x-admin.stat-card label="Temuan nyata hari ini" :value="$summary['real_findings_today']" hint="Target minimal 1 temuan nyata per hari" :tone="$summary['real_findings_today'] > 0 ? 'emerald' : 'amber'" />
            <x-admin.stat-card label="Tanpa temuan hari ini" :value="$summary['no_finding_today']" hint="Kunjungan sah tanpa temuan" />
            <x-admin.stat-card label="Menunggu validasi" :value="$summary['pending']" :tone="$summary['pending'] > 0 ? 'amber' : 'slate'" :href="route('admin.reviews.index', ['category' => 'findings'])" />
            <x-admin.stat-card label="Temuan disetujui" :value="$summary['approved']" tone="emerald" />
        </div>
    @endif

    <x-admin.panel flush title="Daftar kunjungan inspeksi">
        <x-admin.filter-bar :action="route('admin.inspeksi.index')" :reset-url="route('admin.inspeksi.index')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" placeholder="Kode atau Section" />
            <x-admin.filter-select name="field_team_id" label="Tim" :options="$teamOptions" :value="$filters['field_team_id']" placeholder="Semua tim" />
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$filters['penyulang_id']" placeholder="Semua penyulang" />
            <x-admin.filter-select name="result" label="Hasil" :options="$resultFilters" :value="$filters['result']" placeholder="Semua hasil" />
            <x-admin.filter-input type="date" name="date_from" label="Dari" :value="$filters['date_from']" />
            <x-admin.filter-input type="date" name="date_to" label="Sampai" :value="$filters['date_to']" />
        </x-admin.filter-bar>
        @if ($load->failed('inspections'))
            <x-admin.error-state />
        @elseif ($inspections->isEmpty())
            <x-admin.empty-state title="Belum ada kunjungan inspeksi." description="Kunjungan masuk dari aplikasi tim INSPEKSI atau dicatat Admin melalui tombol Catat inspeksi." />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Waktu kunjungan</th><th class="px-4 py-3">Tim</th><th class="px-4 py-3">Penyulang / Section</th><th class="px-4 py-3">Hasil</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3"></th>
                </x-slot:head>
                @foreach ($inspections as $inspection)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs font-bold text-slate-800">{{ $inspection->code }}</td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->visited_at->copy()->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->fieldTeam?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->penyulang?->nama_penyulang ?? '-' }}<div class="text-slate-500">{{ $inspection->section_name ?? '-' }}</div></td>
                        <td class="px-4 py-3 text-xs">
                            @if ($inspection->has_finding)
                                <x-admin.status-badge tone="blue">{{ $inspection->findings_count }} temuan</x-admin.status-badge>
                                @if ($inspection->pending_count > 0)<x-admin.status-badge tone="amber">{{ $inspection->pending_count }} menunggu</x-admin.status-badge>@endif
                            @else
                                <x-admin.status-badge>Tidak ada temuan</x-admin.status-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->source === 'ADMIN' ? 'Input Admin' : 'Aplikasi' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.inspeksi.show', $inspection) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$inspections" label="kunjungan" />
        @endif
    </x-admin.panel>

    <x-admin.panel title="Tim INSPEKSI" description="Roster dari Master Data.">
        @if ($load->failed('teams'))
            <x-admin.error-state />
        @elseif ($teams->isEmpty())
            <x-admin.empty-state title="Belum ada tim INSPEKSI." description="Tambahkan tim dan petugas di Master Data." />
        @else
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($teams as $team)
                    <li class="rounded-lg border border-slate-200 p-3 text-sm"><strong>{{ $team->name }}</strong><div class="text-xs text-slate-500">{{ $team->code }} · {{ $team->personnel_count }} petugas aktif · {{ $team->is_active ? 'Aktif' : 'Nonaktif' }}</div></li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</div>
@endsection
