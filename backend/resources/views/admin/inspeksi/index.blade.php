@extends('layouts.admin')

@section('title', 'Inspeksi')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $resultFilters = \App\Http\Controllers\Admin\InspectionController::RESULT_FILTERS;
    $summaryReady = ! $load->failed('summary');
@endphp

<div class="mx-auto max-w-7xl space-y-3 pb-8">
    <x-admin.page-header
        section="Pekerjaan"
        title="INSPEKSI"
        state="partial"
        description="Pantau kunjungan, temuan per titik, dan validasi Admin. Kunjungan tanpa temuan tetap dicatat."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.inspeksi.create')">Catat kunjungan</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Satu ringkasan saja: jumlah kunjungan tidak digandakan di panel lain. --}}
    <section aria-label="Ringkasan INSPEKSI" class="rounded-xl border border-blue-200 bg-blue-50/50 p-3 shadow-sm sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Monitor INSPEKSI</span>
                <span class="text-xs font-semibold text-slate-700">Kunjungan dan validasi</span>
            </div>
            <a href="{{ route('admin.reviews.index', ['category' => 'findings']) }}" class="text-xs font-semibold text-blue-700 hover:underline">Buka Review &amp; Validasi</a>
        </div>

        @if ($summaryReady)
            <div class="mt-3 grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-5">
                <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Kunjungan hari ini</p>
                    <p class="mt-1 text-2xl font-bold text-blue-700">{{ $summary['visits_today'] }}</p>
                    <p class="text-[10px] text-slate-500">Kunjungan tercatat</p>
                </div>
                <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Temuan nyata hari ini</p>
                    <p class="mt-1 text-2xl font-bold {{ $summary['real_findings_today'] > 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $summary['real_findings_today'] }}</p>
                    <p class="text-[10px] text-slate-500">Target minimal 1 temuan per hari</p>
                </div>
                <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Tanpa temuan hari ini</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['no_finding_today'] }}</p>
                    <p class="text-[10px] text-slate-500">Kunjungan sah tanpa temuan</p>
                </div>
                <a href="{{ route('admin.reviews.index', ['category' => 'findings']) }}" class="rounded-lg border border-amber-200 bg-white px-3 py-2.5 hover:bg-amber-50">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Menunggu validasi</p>
                    <p class="mt-1 text-2xl font-bold {{ $summary['pending'] > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $summary['pending'] }}</p>
                    <p class="text-[10px] text-slate-500">Buka antrean temuan</p>
                </a>
                <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Temuan disetujui</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-700">{{ $summary['approved'] }}</p>
                    <p class="text-[10px] text-slate-500">Setelah keputusan Admin</p>
                </div>
            </div>
        @endif

        @if (! $load->failed('teams') && $teams->isEmpty())
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                Tim INSPEKSI belum tersedia di Master Data. Lengkapi roster sebelum kunjungan operasional dicatat.
            </p>
        @endif
    </section>

    @if (! $summaryReady)
        <x-admin.error-state :inset="false" />
    @endif

    <x-admin.panel
        flush
        title="Daftar kunjungan inspeksi"
        description="Cari dan filter kunjungan; buka detail untuk melihat temuan dan keputusan validasi."
    >
        <x-admin.filter-bar
            :action="route('admin.inspeksi.index')"
            :reset-url="route('admin.inspeksi.index')"
            :active="$hasFilter"
        >
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
            <div class="px-4 py-4 text-sm text-slate-600">
                <p class="font-semibold text-slate-800">Belum ada kunjungan inspeksi.</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">Belum ada kunjungan tersimpan atau yang cocok dengan filter. Kunjungan dicatat dari aplikasi INSPEKSI atau oleh Admin.</p>
            </div>
        @else
            <x-admin.table>
                <x-slot:head>
                    <th class="px-4 py-3">Kode</th>
                    <th class="px-4 py-3">Waktu kunjungan</th>
                    <th class="px-4 py-3">Tim</th>
                    <th class="px-4 py-3">Penyulang / Section</th>
                    <th class="px-4 py-3">Hasil</th>
                    <th class="px-4 py-3">Sumber</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>
                @foreach ($inspections as $inspection)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs font-bold text-slate-800">{{ $inspection->code }}</td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->visited_at->copy()->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->fieldTeam?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">
                            {{ $inspection->penyulang?->nama_penyulang ?? '-' }}
                            <div class="text-slate-500">{{ $inspection->section_name ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if ($inspection->has_finding)
                                <x-admin.status-badge tone="blue">{{ $inspection->findings_count }} temuan</x-admin.status-badge>
                                @if ($inspection->pending_count > 0)
                                    <x-admin.status-badge tone="amber">{{ $inspection->pending_count }} menunggu</x-admin.status-badge>
                                @endif
                            @else
                                <x-admin.status-badge>Tidak ada temuan</x-admin.status-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $inspection->source === 'ADMIN' ? 'Input Admin' : 'Aplikasi' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.inspeksi.show', $inspection) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$inspections" label="kunjungan" />
        @endif
    </x-admin.panel>

    <details class="group overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50/60 px-4 py-3 text-xs font-semibold text-amber-900 [&::-webkit-details-marker]:hidden">
            <span>Aturan inspeksi dan kesiapan otomatisasi</span>
            <span class="text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden text-amber-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="border-t border-amber-100 px-4 py-3 text-xs leading-6 text-slate-600">
            Ranking dan rotasi area kunjungan serta rekomendasi tim penerima temuan belum otomatis karena formula prioritas resmi belum tersedia.
            Saran tim dapat dicatat petugas atau Admin. Temuan baru dapat menjadi dasar pekerjaan setelah keputusan validasi Admin.
        </div>
    </details>

    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
            <span>Roster tim INSPEKSI dan petugas</span>
            <span class="text-xs text-blue-700 group-open:hidden">Buka +</span>
            <span class="hidden text-xs text-blue-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="border-t border-slate-100 p-4">
            <p class="mb-3 text-xs text-slate-500">Tim INSPEKSI dari Master Data; tim dan petugas yang belum tersedia tidak dibuat otomatis.</p>
            @if ($load->failed('teams'))
                <x-admin.error-state />
            @elseif ($teams->isEmpty())
                <p class="text-sm font-semibold text-slate-700">Belum ada tim INSPEKSI.</p>
                <p class="mt-1 text-xs text-slate-500">Tambahkan tim dan petugas resmi melalui Master Data.</p>
            @else
                <ul class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    @foreach ($teams as $team)
                        <li class="rounded-lg border border-slate-200 p-3 text-sm">
                            <strong>{{ $team->name }}</strong>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ $team->code }} · {{ $team->personnel_count }} petugas aktif · {{ $team->is_active ? 'Aktif' : 'Nonaktif' }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>
</div>
@endsection