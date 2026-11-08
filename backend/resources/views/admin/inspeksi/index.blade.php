@extends('layouts.admin')

@section('title', 'Inspeksi')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $resultFilters = \App\Http\Controllers\Admin\InspectionController::RESULT_FILTERS;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" title="INSPEKSI" state="partial"
        description="Monitoring kunjungan, temuan per titik, dan proses validasi Admin. Kunjungan tanpa temuan tetap dicatat, tetapi tidak dianggap sebagai temuan.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.inspeksi.create')">Catat kunjungan</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <section class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <div class="mb-2 flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wide">
                    <span class="rounded-md bg-blue-600 px-2 py-1 text-white">MONITOR INSPEKSI</span>
                    <span class="rounded-md border border-blue-200 bg-white px-2 py-1 text-blue-700">Kunjungan dan validasi</span>
                </div>
                <h2 class="text-lg font-bold text-slate-900">Pantau kunjungan sampai tindak lanjut</h2>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">Kunjungan dicatat oleh tim INSPEKSI atau Admin. Temuan yang dilaporkan harus divalidasi terlebih dahulu sebelum dijadikan dasar pekerjaan.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:min-w-[300px]">
                @if ($load->failed('summary'))
                    <div class="col-span-2 rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-600">Ringkasan belum dapat dimuat.</div>
                @else
                    <div class="rounded-lg border border-blue-100 bg-white p-3">
                        <div class="text-[10px] font-bold uppercase text-slate-500">Kunjungan hari ini</div>
                        <div class="mt-1 text-2xl font-bold text-blue-700">{{ $summary['visits_today'] }}</div>
                    </div>
                    <a href="{{ route('admin.reviews.index', ['category' => 'findings']) }}" class="rounded-lg border border-amber-200 bg-white p-3 hover:bg-amber-50">
                        <div class="text-[10px] font-bold uppercase text-slate-500">Butuh validasi</div>
                        <div class="mt-1 text-2xl font-bold text-amber-700">{{ $summary['pending'] }}</div>
                    </a>
                @endif
            </div>
        </div>
        @if (! $load->failed('teams') && $teams->isEmpty())
            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Tim INSPEKSI belum tersedia di Master Data. Data roster harus dilengkapi sebelum kunjungan operasional dicatat.</p>
        @endif
    </section>

    <details class="group rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-xs font-semibold text-amber-800">
            <span>Aturan inspeksi dan kesiapan otomatisasi</span>
            <span class="text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden text-amber-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="border-t border-amber-100 px-4 py-3 text-xs leading-relaxed text-slate-600">
            Ranking/rotasi area kunjungan dan rekomendasi tim penerima temuan belum otomatis karena formula prioritas resmi belum tersedia. Saran tim dapat dicatat petugas atau Admin; keputusan tindak lanjut tetap dilakukan Admin saat validasi. Temuan tidak boleh dianggap disetujui sebelum ada keputusan validasi.
        </div>
    </details>

    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Ringkasan kunjungan dan temuan</h2>
            <p class="text-xs text-slate-500">Indikator hari ini dan antrean validasi; jumlah temuan berbeda dari jumlah kunjungan.</p>
        </div>
        <a href="{{ route('admin.reviews.index', ['category' => 'findings']) }}" class="text-xs font-semibold text-blue-700 hover:underline">Buka Review &amp; Validasi</a>
    </div>

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

    <x-admin.panel flush title="Daftar kunjungan inspeksi" description="Gunakan filter untuk menelusuri kunjungan. Detail menampilkan seluruh temuan dan keputusan validasi.">
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

    <details class="group rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
            <span>Roster tim INSPEKSI dan petugas</span>
            <span class="text-xs text-blue-700 group-open:hidden">Buka +</span>
            <span class="hidden text-xs text-blue-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="border-t border-slate-100 p-3">
            <x-admin.panel title="Tim INSPEKSI" description="Roster dari Master Data; tim yang belum tersedia tidak dibuat secara otomatis.">
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
    </details>
</div>
@endsection
