@extends('layouts.admin')

@section('title', 'APKUK')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $tabs = \App\Http\Controllers\Admin\ApkukController::TABS;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" title="APKUK" state="live"
        description="Laporan APKUK dari SPS BOS hasil sinkronisasi dan rekonsiliasi. Laporan yang tidak lagi ada di sumber berstatus menunggu rekonsiliasi (tidak dihitung aktif) sampai muncul kembali atau diarsipkan Admin atas konfirmasi pemilik data; histori tidak pernah dihapus. Halaman ini tidak menulis ke Google Sheets.">
        <x-slot:actions>
            <x-admin.button :href="route('admin.work-orders.create')">Buat WO APKUK</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-admin.stat-card label="Laporan aktif" :value="$summary['active']" :hint="'Sinkron terakhir '.($summary['last_synced_at'] ? \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->timezone($tz)->format('d/m H:i').' WIB' : '-')" />
            <x-admin.stat-card label="Menunggu rekonsiliasi" :value="$summary['pending']" hint="Tidak ada di SPS BOS terbaru; tidak dihitung aktif" tone="amber" :href="route('admin.apkuk-reconciliation.index')" />
            <x-admin.stat-card label="Histori (diarsipkan)" :value="$summary['history']" hint="Diarsipkan Admin; tidak dihapus" :href="route('admin.apkuk.index', ['tab' => 'histori'])" />
            <x-admin.stat-card label="Lokasi layak WO" :value="$summary['eligible']" hint="Laporan terbaru per tiang, kurang > 0, status BELUM EKSEKUSI/KURANG, penyulang cocok" tone="blue" :href="route('admin.work-orders.create')" />
            <x-admin.stat-card label="Kebutuhan (bh)" :value="$summary['need_bh']" hint="Jumlah kurang_bh pada lokasi layak" tone="amber" />
            <x-admin.stat-card label="WO APKUK aktif" :value="$summary['wo_active']" :href="route('admin.work-orders.index')" />
            <x-admin.stat-card label="WO APKUK selesai" :value="$summary['wo_done']" tone="emerald" />
            <x-admin.stat-card label="APKUK terpasang (bh)" :value="$summary['installed_bh']" hint="Realisasi dari laporan pelaksanaan WO" tone="emerald" :href="route('admin.apkuk.index', ['tab' => 'progres'])" />
            <x-admin.stat-card label="Penyulang master aktif" :value="$masterPenyulangCount" hint="Dipakai untuk mencocokkan penyulang laporan" />
        </div>
    @endif

    <x-admin.panel flush>
        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3" aria-label="Tab APKUK">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('admin.apkuk.index', ['tab' => $key]) }}" data-loading-link class="whitespace-nowrap rounded-t-md border-b-2 px-3 py-2 text-sm font-semibold {{ $tab === $key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
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
                    <th class="px-4 py-3">Tiang</th><th class="px-4 py-3">ID laporan</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Penyulang / Section</th><th class="px-4 py-3 text-right">Terpasang / Kurang</th><th class="px-4 py-3">Status sumber</th><th class="px-4 py-3">{{ match ($tab) { 'aktif' => 'Kelayakan WO', 'menunggu' => 'Rekonsiliasi', default => 'Diarsipkan' } }}</th>
                </x-slot:head>
                @forelse ($rows as $row)
                    @php $candidate = $candidates->get($row->id); @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs font-semibold text-slate-900">{{ $row->no_tiang }}</td>
                        <td class="px-4 py-3 font-mono text-[11px]">{{ $row->source_id }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row->tanggal ? \Illuminate\Support\Carbon::parse($row->tanggal)->format('d/m/Y') : '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row->penyulang ?? '-' }}<div class="text-slate-500">{{ $row->section ?? '-' }}</div></td>
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
