@extends('layouts.admin')

@section('title', 'APKUK')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $tabs = \App\Http\Controllers\Admin\ApkukController::TABS;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    {{-- APKUK-DATA-REDESIGN-V2 --}}
    <x-admin.page-header section="Data" title="APKUK - Data & Pemasangan" state="live"
        description="Pantau laporan SPS BOS, lokasi yang memenuhi syarat WO, kebutuhan pemasangan, dan progres pekerjaan.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.work-orders.create')">Buat WO APKUK</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <section aria-label="Prioritas kebutuhan APKUK" class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold text-white">MONITOR DATA APKUK</span>
                    <h2 class="mt-3 text-lg font-bold text-slate-900">Peta kebutuhan pemasangan</h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">Lokasi yang memenuhi syarat dapat dipertimbangkan untuk WO. Kebutuhan BH bukan jumlah pekerjaan, dan laporan sumber bukan bukti pemasangan di lapangan.</p>
                </div>
                <p class="text-xs text-slate-500">Sinkron terakhir: <strong class="text-slate-700">{{ $summary['last_synced_at'] ? \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->timezone($tz)->format('d/m/Y H:i').' WIB' : 'Belum tercatat' }}</strong></p>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <a href="{{ route('admin.work-orders.create') }}" class="rounded-xl border border-blue-200 bg-white p-4 transition hover:border-blue-400">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Lokasi layak WO</p>
                    <div class="mt-1 flex items-baseline gap-2"><strong class="text-3xl font-black text-blue-700">{{ number_format($summary['eligible'], 0, ',', '.') }}</strong><span class="text-xs text-slate-500">lokasi kandidat</span></div>
                    <p class="mt-2 text-xs text-slate-500">Laporan terakhir per tiang, sesuai aturan kelayakan.</p>
                </a>
                <div class="rounded-xl border border-amber-200 bg-white p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Kebutuhan pemasangan</p>
                    <div class="mt-1 flex items-baseline gap-2"><strong class="text-3xl font-black text-amber-700">{{ number_format($summary['need_bh'], 0, ',', '.') }}</strong><span class="text-xs text-slate-500">BH</span></div>
                    <p class="mt-2 text-xs text-slate-500">Penjumlahan kurang BH dari lokasi yang layak.</p>
                </div>
            </div>
            <details class="mt-4 rounded-lg border border-blue-100 bg-white/80 p-3 text-xs text-slate-600">
                <summary class="cursor-pointer font-semibold text-slate-700">Aturan sumber, rekonsiliasi, dan kelayakan WO</summary>
                <div class="mt-3 space-y-2 leading-relaxed">
                    <p>Laporan berasal dari hasil sinkronisasi SPS BOS. Halaman ini tidak menulis ke Google Sheets.</p>
                    <p>Laporan yang hilang dari snapshot terbaru menunggu rekonsiliasi dan tidak dihitung aktif. Pengarsipan membutuhkan keputusan Admin; riwayat tetap tersimpan.</p>
                    <p>Kelayakan mempertimbangkan laporan terbaru per tiang, kebutuhan kurang BH, status sumber, kecocokan penyulang, dan aturan kandidat lainnya. Penetapan WO tetap proses terpisah.</p>
                </div>
            </details>
        </section>

        <section class="space-y-3" aria-labelledby="apkuk-source-heading">
            <div>
                <h2 id="apkuk-source-heading" class="text-sm font-bold text-slate-900">Kondisi laporan dan master</h2>
                <p class="text-xs text-slate-500">Jumlah laporan berdasarkan status data, bukan jumlah Work Order.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-admin.stat-card label="Laporan aktif" :value="$summary['active']" hint="Ada dalam sumber yang aktif" />
                <x-admin.stat-card label="Menunggu rekonsiliasi" :value="$summary['pending']" hint="Perlu keputusan sebelum dihitung aktif" tone="amber" :href="route('admin.apkuk-reconciliation.index')" />
                <x-admin.stat-card label="Histori (diarsipkan)" :value="$summary['history']" hint="Tersimpan, tidak dihapus" :href="route('admin.apkuk.index', ['tab' => 'histori'])" />
                <x-admin.stat-card label="Penyulang master aktif" :value="$masterPenyulangCount" hint="Referensi pencocokan laporan" />
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="apkuk-work-heading">
            <div>
                <h2 id="apkuk-work-heading" class="text-sm font-bold text-slate-900">Realisasi pekerjaan pemasangan</h2>
                <p class="text-xs text-slate-500">Hanya WO tersimpan dan hasil pemasangan yang tercatat, bukan rencana atau kandidat.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-admin.stat-card label="WO APKUK aktif" :value="$summary['wo_active']" hint="WO yang sedang berjalan" :href="route('admin.work-orders.index')" />
                <x-admin.stat-card label="WO APKUK selesai" :value="$summary['wo_done']" tone="emerald" hint="WO berstatus selesai" />
                <x-admin.stat-card label="APKUK terpasang (BH)" :value="$summary['installed_bh']" hint="Realisasi laporan pekerjaan" tone="emerald" :href="route('admin.apkuk.index', ['tab' => 'progres'])" />
            </div>
        </section>
    @endif

    <x-admin.panel flush>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/70 px-4 py-3">
            <div>
                <h2 class="text-sm font-bold text-slate-900">Telusuri laporan dan progres APKUK</h2>
                <p class="mt-0.5 text-xs text-slate-500">Gunakan tab, pencarian, dan filter untuk menemukan laporan atau WO terkait.</p>
            </div>
            <a href="{{ route('admin.apkuk-reconciliation.index') }}" class="text-xs font-semibold text-blue-700 hover:underline">Buka Rekonsiliasi APKUK</a>
        </div>
        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3" aria-label="Tab APKUK">
            @foreach ($tabs as $key => $label)
                @php
                    $tabCount = $load->failed('summary') ? null : match ($key) {
                        'aktif' => $summary['active'],
                        'menunggu' => $summary['pending'],
                        'histori' => $summary['history'],
                        default => null,
                    };
                @endphp
                <a href="{{ route('admin.apkuk.index', ['tab' => $key]) }}" data-loading-link class="whitespace-nowrap rounded-t-md border-b-2 px-3 py-2.5 text-xs font-bold sm:text-sm {{ $tab === $key ? 'border-blue-600 bg-blue-50/60 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($tabCount !== null) <span class="ml-1 text-[10px] font-semibold opacity-75">({{ number_format($tabCount, 0, ',', '.') }})</span>@endif</a>
            @endforeach
        </nav>
        <x-admin.filter-bar :action="route('admin.apkuk.index')" :reset-url="route('admin.apkuk.index', ['tab' => $tab])" :active="$hasFilter">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" :placeholder="$tab === 'progres' ? 'Kode WO atau no. tiang' : 'No. tiang atau ID laporan'" />
            @if ($tab !== 'progres')
                <x-admin.filter-select name="penyulang" label="Penyulang (sumber)" :options="$penyulangOptions" :value="$filters['penyulang']" placeholder="Semua penyulang" />
                <x-admin.filter-select name="status" label="Status sumber" :options="$statusOptions" :value="$filters['status']" placeholder="Semua status" />
            @endif
        </x-admin.filter-bar>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-xs text-slate-600">
            <span><strong class="text-slate-800">{{ number_format($rows->total(), 0, ',', '.') }}</strong> {{ $tab === 'progres' ? 'WO' : 'laporan' }} cocok dengan tab dan filter ini.</span>
            <span>{{ match ($tab) {
                'aktif' => 'Status sumber bukan status WO.',
                'menunggu' => 'Belum dihitung sebagai laporan aktif.',
                'histori' => 'Riwayat tetap disimpan.',
                default => 'Progres berdasarkan WO yang tersimpan.',
            } }}</span>
        </div>

        @if ($load->failed('rows'))
            <x-admin.error-state />
        @elseif ($tab === 'progres')
            <x-admin.table>
                <x-slot:head><th class="px-4 py-3">WO</th><th class="px-4 py-3">Tiang</th><th class="px-4 py-3">POSKO</th><th class="px-4 py-3 text-right">Target</th><th class="px-4 py-3 text-right">Terpasang</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Status</th></x-slot:head>
                @forelse ($rows as $wo)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs font-bold"><a class="text-blue-700" href="{{ route('admin.work-orders.show', $wo) }}">{{ $wo->code }}</a></td>
                        <td class="px-4 py-3 text-xs">{{ $wo->apkukTarget?->no_tiang }}</td>
                        <td class="px-4 py-3 text-xs">{{ $wo->posko?->nama_posko }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $wo->apkukTarget?->target_quantity }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $wo->apkukTarget?->installed_quantity ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $wo->apkukTarget?->remaining_quantity ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs"><x-admin.wo-status :work-order="$wo" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-admin.empty-state title="Belum ada WO APKUK." description="Buat WO dari lokasi yang layak di halaman Buat WO APKUK." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$rows" label="WO APKUK" />
        @else
            <x-admin.table min-width="min-w-[960px]">
                <x-slot:head>
                    <th class="px-4 py-3">Tiang</th><th class="px-4 py-3">ID laporan</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Penyulang / Section</th><th class="px-4 py-3 text-right">Terpasang / Kurang (BH)</th><th class="px-4 py-3">Status sumber</th><th class="px-4 py-3">{{ match ($tab) { 'aktif' => 'Kelayakan WO', 'menunggu' => 'Rekonsiliasi', default => 'Diarsipkan' } }}</th>
                </x-slot:head>
                @forelse ($rows as $row)
                    @php
                        $candidate = $candidates->get($row->id);
                        $section = \App\Services\Apkuk\ApkukSectionService::effective($row->section, $sectionOverrides[$row->id] ?? null);
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs font-semibold"><a class="text-blue-700 hover:text-blue-900" href="{{ route('admin.apkuk.show', $row->id) }}">{{ $row->no_tiang }}</a></td>
                        <td class="px-4 py-3 font-mono text-[11px]">{{ $row->source_id }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row->tanggal ? \Illuminate\Support\Carbon::parse($row->tanggal)->format('d/m/Y') : '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row->penyulang ?? '-' }}
                            <div class="text-slate-500">{{ $row->section ?? '-' }}</div>
                            @if ($section['origin'] === \App\Services\Apkuk\ApkukSectionService::ORIGIN_OVERRIDE)
                                <div class="text-blue-700">Koreksi Admin: {{ $section['value'] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row->terpasang_bh ?? '-' }} / {{ $row->kurang_bh ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row->status ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">
                            @if ($tab === 'menunggu')
                                <a href="{{ route('admin.apkuk-reconciliation.index') }}" class="font-semibold text-amber-700 hover:text-amber-900">Menunggu keputusan — tinjau</a>
                            @elseif ($tab === 'histori')
                                {{ \Illuminate\Support\Carbon::parse($row->source_missing_at)->timezone($tz)->format('d/m/Y H:i') }} WIB
                            @elseif ($candidate?->isEligible())
                                <a href="{{ route('admin.work-orders.create', ['apkuk_id' => $row->id]) }}" class="font-semibold text-emerald-700 hover:text-emerald-900">Layak — buat WO</a>
                            @elseif ($candidate)
                                <span class="text-slate-500">{{ collect($candidate->issues)->map(fn ($code) => $issueLabels[$code] ?? $code)->first() }}@if (count($candidate->issues) > 1) (+{{ count($candidate->issues) - 1 }})@endif</span>
                                @if ($candidate->activeWorkOrderCode)<div class="text-amber-700">WO aktif {{ $candidate->activeWorkOrderCode }}</div>@endif
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-admin.empty-state :title="match ($tab) { 'aktif' => 'Tidak ada laporan aktif yang cocok.', 'menunggu' => 'Tidak ada laporan yang menunggu rekonsiliasi.', default => 'Tidak ada laporan histori.' }" /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$rows" label="laporan" />
        @endif
    </x-admin.panel>
</div>
@endsection
