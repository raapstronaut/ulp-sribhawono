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
        description="Keadaan khusus pelaksanaan pekerjaan (kendala, penjadwalan ulang, limpahan, keterlambatan) dan bahan analisis frekuensi gangguan dari data gangguan PLN pusat."
    />

    @php $tz = \App\Services\ShiftService::TIMEZONE; @endphp
    @if ($load->failed('exception_counts'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-admin.stat-card label="Kendala terbuka" :value="$exceptionCounts['issues']" :tone="$exceptionCounts['issues'] > 0 ? 'amber' : 'slate'" hint="Tidak dapat dilaksanakan, lokasi/data tidak sesuai, kendala lapangan" />
            <x-admin.stat-card label="Reschedule menunggu ACC" :value="$exceptionCounts['reschedules']" :href="route('admin.reviews.index', ['category' => 'reschedules'])" :tone="$exceptionCounts['reschedules'] > 0 ? 'amber' : 'slate'" />
            <x-admin.stat-card label="Limpahan menunggu ACC" :value="$exceptionCounts['escalations']" :href="route('admin.reviews.index', ['category' => 'escalations'])" :tone="$exceptionCounts['escalations'] > 0 ? 'amber' : 'slate'" />
            <x-admin.stat-card label="WO terlambat" :value="$exceptionCounts['overdue']" :tone="$exceptionCounts['overdue'] > 0 ? 'red' : 'slate'" hint="WO aktif melewati batas waktu" />
        </div>
    @endif

    <x-admin.panel flush title="Kendala pelaksanaan terbuka" description="Dicatat tim lapangan atau Admin. Tindak lanjut nyata (ubah jadwal, limpahan, batal) dilakukan dari halaman WO; di sini dicatat keputusan tindak lanjutnya.">
        @if ($load->failed('issues'))
            <x-admin.error-state />
        @elseif ($issues->isEmpty())
            <x-admin.empty-state title="Tidak ada kendala terbuka." />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($issues as $issue)
                    <article class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
                        <div class="text-sm lg:col-span-2">
                            <a href="{{ route('admin.work-orders.show', $issue->workOrder) }}" class="font-bold text-blue-700 hover:text-blue-900">{{ $issue->workOrder->code }}</a>
                            <x-admin.status-badge tone="amber">{{ $issue->typeLabel() }}</x-admin.status-badge>
                            <span class="text-xs text-slate-500">{{ $issue->workOrder->teamLabel() }} · {{ $issue->workOrder->posko?->nama_posko ?? '-' }} · {{ $issue->workOrder->status->label() }}</span>
                            <p class="mt-1 text-xs text-slate-700">{{ $issue->description }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">Dilaporkan {{ $issue->reportedByTeam?->name ?? $issue->reportedBy?->displayName() }} · {{ $issue->created_at->copy()->timezone($tz)->format('d/m/Y H:i') }} WIB</p>
                        </div>
                        <form method="POST" action="{{ route('admin.special-case.issues.resolve', $issue) }}" class="space-y-2">
                            @csrf
                            <select name="resolution_action" required class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                <option value="">Pilih tindak lanjut</option>
                                @foreach ($resolutionOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <input type="text" name="resolution_note" required minlength="5" maxlength="1000" placeholder="Catatan tindak lanjut (wajib)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                            <x-admin.button size="sm" class="w-full">Tandai ditindaklanjuti</x-admin.button>
                        </form>
                    </article>
                @endforeach
            </div>
            <x-admin.pagination :paginator="$issues" label="kendala" />
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="WO terlambat (10 terlama)">
        @if ($load->failed('overdue'))
            <x-admin.error-state />
        @elseif ($overdue->isEmpty())
            <x-admin.empty-state title="Tidak ada WO terlambat." />
        @else
            <x-admin.table min-width="min-w-[640px]">
                <x-slot:head><th class="px-4 py-3">WO</th><th class="px-4 py-3">Tim</th><th class="px-4 py-3">POSKO / Penyulang</th><th class="px-4 py-3">Batas waktu</th><th class="px-4 py-3">Status</th></x-slot:head>
                @foreach ($overdue as $wo)
                    <tr>
                        <td class="px-4 py-2 text-xs font-bold"><a class="text-blue-700" href="{{ route('admin.work-orders.show', $wo) }}">{{ $wo->code }}</a></td>
                        <td class="px-4 py-2 text-xs">{{ $wo->teamLabel() }} · {{ $wo->workTypeLabel() }}</td>
                        <td class="px-4 py-2 text-xs">{{ $wo->posko?->nama_posko ?? '-' }} / {{ $wo->penyulang?->nama_penyulang ?? '-' }}</td>
                        <td class="px-4 py-2 text-xs text-red-700">{{ $wo->due_at?->copy()->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
                        <td class="px-4 py-2 text-xs"><x-admin.wo-status :work-order="$wo" /></td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.panel>

    <x-admin.notice tone="warning" title="Metode Special Case gangguan belum final:">
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
