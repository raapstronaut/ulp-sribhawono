@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $groupLabels = \App\Services\DashboardService::GROUP_LABELS;
    $groupTones = ['belum' => 'bg-blue-500', 'dikerjakan' => 'bg-indigo-500', 'menunggu' => 'bg-amber-500', 'selesai' => 'bg-emerald-500', 'batal' => 'bg-slate-400'];
    $groupTotal = array_sum($statusGroups);
    $trendMax = max(1, collect($trend)->max(fn ($d) => max($d['created'], $d['completed'])));
    $penyulangMax = max(1, collect($byPenyulang)->max('active') ?? 0);
    $pendingTotal = array_sum($pending);
    $syncLabel = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('d/m H:i').' WIB' : 'belum pernah';
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Ringkasan" title="Dashboard" state="live"
        :description="'Diperbarui '.$context['updated_at_label'].' · Shift '.$shiftData['code'].' ('.$shiftData['name'].', '.$shiftData['time'].') · '.$context['active_poskos'].' Posko aktif · '.$context['active_penyulangs'].' Penyulang aktif'">
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2" data-loading-form>
                <label for="period" class="text-xs font-semibold text-slate-600">Periode</label>
                <select id="period" name="period" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm" onchange="this.form.requestSubmit()">
                    @foreach (\App\Services\DashboardService::PERIODS as $value => $label)
                        <option value="{{ $value }}" @selected($context['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="rounded-lg border px-3 py-1.5 text-sm">Terapkan</button></noscript>
            </form>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4">
        <x-admin.stat-card label="Total Work Order" :value="$kpis['total']" :hint="$kpis['dibuat_periode'].' dibuat '.\Illuminate\Support\Str::lower($context['period_label'])" :href="route('admin.analytics.index')" />
        <x-admin.stat-card label="WO aktif" :value="$kpis['active']" :hint="$kpis['belum'].' belum dikerjakan · '.$kpis['dikerjakan'].' dikerjakan'" tone="blue" :href="route('admin.work-orders.index')" />
        <x-admin.stat-card label="Selesai ({{ \Illuminate\Support\Str::lower($context['period_label']) }})" :value="$kpis['selesai_periode']" hint="Selesai & selesai diteruskan" tone="emerald" />
        <x-admin.stat-card label="Terlambat" :value="$kpis['terlambat']" hint="WO aktif melewati batas waktu" :tone="$kpis['terlambat'] > 0 ? 'red' : 'slate'" :href="route('admin.special-case.index')" />
        <x-admin.stat-card label="Menunggu keputusan Admin" :value="$pendingTotal" :hint="$pending['reports'].' laporan · '.$pending['reschedules'].' reschedule · '.$pending['escalations'].' limpahan · '.$pending['findings'].' temuan'" :tone="$pendingTotal > 0 ? 'amber' : 'slate'" :href="route('admin.reviews.index')" />
        <x-admin.stat-card label="Kendala terbuka" :value="$pending['issues']" hint="Special case pelaksanaan" :tone="$pending['issues'] > 0 ? 'amber' : 'slate'" :href="route('admin.special-case.index')" />
        <x-admin.stat-card label="Laporan APKUK aktif" :value="$apkuk['active_reports']" :hint="$apkuk['history_reports'].' histori bertanda · sinkron '.$syncLabel($apkuk['last_synced_at'])" :href="route('admin.apkuk.index')" />
        <x-admin.stat-card label="Gangguan 7 hari" :value="$gangguan['last_7_days']" :hint="$gangguan['last_30_days'].' dalam 30 hari · sinkron '.$syncLabel($gangguan['last_synced_at'])" :href="route('admin.special-case.index')" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-admin.panel title="Status seluruh Work Order" description="Jumlah per kelompok status lifecycle.">
            @if ($groupTotal === 0)
                <x-admin.empty-state title="Belum ada Work Order." description="WO dibuat dari menu Work Order, HARSUS, ROW, atau persetujuan limpahan/temuan." />
            @else
                <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Komposisi status WO">
                    @foreach ($statusGroups as $group => $count)
                        @if ($count > 0)<div class="{{ $groupTones[$group] }}" style="width: {{ round($count / $groupTotal * 100, 2) }}%" title="{{ $groupLabels[$group] }}: {{ $count }}"></div>@endif
                    @endforeach
                </div>
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($statusGroups as $group => $count)
                        <li class="flex items-center justify-between"><span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $groupTones[$group] }}"></span>{{ $groupLabels[$group] }}</span><strong>{{ number_format($count, 0, ',', '.') }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </x-admin.panel>

        <x-admin.panel title="Perkembangan 14 hari" description="WO dibuat (biru) dan diselesaikan (hijau) per hari WIB." class="lg:col-span-2">
            <div class="flex h-40 items-end gap-1" role="img" aria-label="Grafik WO dibuat dan selesai 14 hari terakhir">
                @foreach ($trend as $day)
                    <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $day['label'] }}: dibuat {{ $day['created'] }}, selesai {{ $day['completed'] }}">
                        <div class="flex w-full flex-1 items-end justify-center gap-0.5">
                            <div class="w-1/2 rounded-t bg-blue-500" style="height: {{ $day['created'] > 0 ? max(4, round($day['created'] / $trendMax * 100)) : 0 }}%"></div>
                            <div class="w-1/2 rounded-t bg-emerald-500" style="height: {{ $day['completed'] > 0 ? max(4, round($day['completed'] / $trendMax * 100)) : 0 }}%"></div>
                        </div>
                        <span class="text-[9px] text-slate-500">{{ $day['label'] }}</span>
                    </div>
                @endforeach
            </div>
            <details class="mt-3 text-xs text-slate-600">
                <summary class="cursor-pointer font-semibold">Lihat angka</summary>
                <table class="mt-2 w-full table-fixed text-left">
                    <thead><tr class="text-slate-500"><th>Tanggal</th><th>Dibuat</th><th>Selesai</th></tr></thead>
                    <tbody>@foreach ($trend as $day)<tr><td>{{ $day['label'] }}</td><td>{{ $day['created'] }}</td><td>{{ $day['completed'] }}</td></tr>@endforeach</tbody>
                </table>
            </details>
        </x-admin.panel>
    </div>

    <x-admin.panel flush title="Ringkasan per tim" description="WO aktif, menunggu keputusan, terlambat, dan selesai pada periode terpilih.">
        <div class="grid grid-cols-1 divide-y divide-slate-100 md:grid-cols-3 md:divide-x md:divide-y-0">
            @foreach ($teamSummaries as $team)
                <a href="{{ route($team['route']) }}" class="block p-4 hover:bg-slate-50">
                    <div class="flex items-center justify-between"><strong class="text-slate-900">{{ $team['label'] }}</strong><span class="text-[10px] text-slate-500">{{ implode(', ', $team['work_types']) }}</span></div>
                    <dl class="mt-3 grid grid-cols-4 gap-2 text-center">
                        <div><dt class="text-[10px] uppercase text-slate-500">Aktif</dt><dd class="text-xl font-black text-blue-700">{{ $team['active'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase text-slate-500">Menunggu</dt><dd class="text-xl font-black text-amber-600">{{ $team['waiting'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase text-slate-500">Terlambat</dt><dd class="text-xl font-black {{ $team['overdue'] > 0 ? 'text-red-600' : 'text-slate-800' }}">{{ $team['overdue'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase text-slate-500">Selesai</dt><dd class="text-xl font-black text-emerald-700">{{ $team['completed_period'] }}</dd></div>
                    </dl>
                </a>
            @endforeach
        </div>
    </x-admin.panel>

    <x-admin.panel flush :title="'POSKO — Shift '.$shiftData['code'].' hari ini'" description="Target 6 WO APKUK per Posko per shift. Realisasi dari WO APKUK shift berjalan (tidak termasuk yang dibatalkan).">
        @if ($poskoStatuses === [])
            <x-admin.empty-state title="Belum ada Posko aktif." />
        @else
            <div class="hidden md:block">
                <table class="w-full table-fixed text-left text-sm">
                    <thead class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                        <tr><th class="w-1/4 px-4 py-2">Posko</th><th class="w-1/3 px-4 py-2">WO APKUK shift ini</th><th class="px-4 py-2">Selesai</th><th class="px-4 py-2">Aktif</th><th class="px-4 py-2">Terlambat</th><th class="px-4 py-2">Tim</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($poskoStatuses as $posko)
                            <tr>
                                <td class="px-4 py-2.5 font-semibold text-slate-800">{{ $posko['name'] }}</td>
                                <td class="px-4 py-2.5"><div class="text-[11px] font-semibold text-slate-700">{{ $posko['apkuk_total'] }} / Target {{ $posko['apkuk_target'] }}</div><x-admin.bar :value="$posko['apkuk_total']" :max="$posko['apkuk_target']" /></td>
                                <td class="px-4 py-2.5 text-emerald-700">{{ $posko['apkuk_completed'] }}</td>
                                <td class="px-4 py-2.5">{{ $posko['apkuk_active'] }}</td>
                                <td class="px-4 py-2.5 {{ $posko['overdue'] > 0 ? 'text-red-600 font-semibold' : '' }}">{{ $posko['overdue'] }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-500">{{ $posko['teams'] }} tim</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y divide-slate-100 md:hidden">
                @foreach ($poskoStatuses as $posko)
                    <li class="p-4 text-sm">
                        <div class="flex justify-between"><strong>{{ $posko['name'] }}</strong><span class="text-xs">{{ $posko['apkuk_total'] }} / {{ $posko['apkuk_target'] }}</span></div>
                        <div class="mt-1"><x-admin.bar :value="$posko['apkuk_total']" :max="$posko['apkuk_target']" /></div>
                        <p class="mt-1 text-[11px] text-slate-500">Selesai {{ $posko['apkuk_completed'] }} · aktif {{ $posko['apkuk_active'] }} · terlambat {{ $posko['overdue'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-admin.panel title="WO aktif per penyulang" class="lg:col-span-2">
            @if (collect($byPenyulang)->sum('active') === 0)
                <x-admin.empty-state title="Tidak ada WO aktif." />
            @else
                <ul class="space-y-2 text-sm">
                    @foreach (collect($byPenyulang)->where('active', '>', 0) as $row)
                        <li class="grid grid-cols-[8rem_1fr_3rem] items-center gap-3"><span class="truncate text-xs font-semibold">{{ $row['name'] }}</span><x-admin.bar :value="$row['active']" :max="$penyulangMax" /><span class="text-right text-xs">{{ $row['active'] }}@if ($row['overdue'] > 0) <span class="text-red-600">({{ $row['overdue'] }})</span>@endif</span></li>
                    @endforeach
                </ul>
                <p class="mt-2 text-[10px] text-slate-500">Angka merah dalam kurung = terlambat.</p>
            @endif
        </x-admin.panel>
        <x-admin.panel title="Lapangan hari ini">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt>Kunjungan inspeksi</dt><dd class="font-bold">{{ $inspection['visits_today'] }}</dd></div>
                <div class="flex justify-between"><dt>Temuan inspeksi</dt><dd class="font-bold">{{ $inspection['findings_today'] }}</dd></div>
                <div class="flex justify-between"><dt>WO APKUK aktif</dt><dd class="font-bold">{{ $apkuk['wo_active'] }}</dd></div>
                <div class="flex justify-between"><dt>APKUK terpasang ({{ \Illuminate\Support\Str::lower($context['period_label']) }})</dt><dd class="font-bold">{{ $apkuk['installed_period'] }} bh</dd></div>
            </dl>
            <h3 class="mt-5 text-xs font-bold uppercase text-slate-500">Penyulang gangguan terbanyak (30 hari)</h3>
            <ul class="mt-2 space-y-1 text-xs">
                @forelse ($gangguan['top_penyulang'] as $row)
                    <li class="flex justify-between"><span>{{ $row['name'] }}</span><strong>{{ $row['total'] }}</strong></li>
                @empty
                    <li class="text-slate-500">Tidak ada gangguan tercatat.</li>
                @endforelse
            </ul>
        </x-admin.panel>
    </div>

    <x-admin.panel title="Akses cepat">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([['admin.maps.index', 'Buka Peta Jaringan', 'Keypoint, APKUK aktif, gangguan'], ['admin.apkuk.index', 'Buka APKUK', 'Kebutuhan & progres'], ['admin.pasila.index', 'Buka halaman PASILA', 'Konteks shift POSKO'], ['admin.inspeksi.index', 'Buka Inspeksi', 'Kunjungan & temuan'], ['admin.harsus.index', 'Buka HARSUS', 'Pekerjaan khusus'], ['admin.row.index', 'Buka ROW', 'Rabas, pangkas, tebang']] as [$route, $label, $hint])
                <a href="{{ route($route) }}" class="rounded-lg border border-slate-200 p-3 text-sm font-semibold text-blue-700 hover:border-blue-300 hover:bg-blue-50">{{ $label }}<span class="mt-0.5 block text-[10px] font-normal text-slate-500">{{ $hint }}</span></a>
            @endforeach
        </div>
    </x-admin.panel>

    <x-admin.panel flush title="Aktivitas terbaru" description="10 entri terakhir audit Work Order.">
        @if ($recentActivities === [])
            <x-admin.empty-state title="Belum ada aktivitas." />
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($recentActivities as $activity)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                        <span><a href="{{ route('admin.work-orders.show', $activity['work_order_id']) }}" class="font-semibold text-blue-700">{{ $activity['code'] }}</a> · {{ $activity['action_label'] }}@if ($activity['to_status']) → {{ $activity['to_status'] }}@endif</span>
                        <span class="text-xs text-slate-500">{{ $activity['actor'] }} · {{ $activity['at'] }} WIB</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</div>
@endsection
