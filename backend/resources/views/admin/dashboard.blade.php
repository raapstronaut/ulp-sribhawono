@extends('layouts.admin')

@section('content')
@php
    $kpiAbbreviations = [
        'wo_active' => 'WA',
        'wo_completed' => 'WS',
        'wo_overdue' => 'WT',
        'p0_active' => 'P0',
        'pasila' => 'PS',
        'approvals' => 'AP',
    ];
@endphp

<div class="mx-auto max-w-[1600px] space-y-4">
    <section class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold leading-tight text-slate-900">Dashboard Operasional</h1>
                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-blue-700">Tahap A</span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">Ringkasan monitoring dan navigasi; pengelolaan detail tetap di modul terkait.</p>
            </div>

            <dl class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                <div class="flex items-center gap-1.5">
                    <dt class="text-slate-400">Periode</dt>
                    <dd class="font-semibold text-slate-800">{{ $context['period_label'] }}</dd>
                </div>
                <div class="hidden h-4 w-px bg-slate-200 sm:block"></div>
                <div class="flex items-center gap-1.5">
                    <dt class="text-slate-400">Shift</dt>
                    <dd class="font-semibold text-blue-700">{{ $shiftData['code'] }} · {{ $shiftData['name'] }}</dd>
                    <dd class="text-[10px] text-slate-400">{{ $shiftData['time'] }}</dd>
                </div>
                <div class="hidden h-4 w-px bg-slate-200 sm:block"></div>
                <div class="flex items-center gap-1.5">
                    <dt class="text-slate-400">Diperbarui</dt>
                    <dd class="font-semibold text-slate-700">{{ $context['updated_at_label'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="mt-3 flex flex-col gap-2 border-t border-slate-100 pt-3 lg:flex-row lg:items-end">
            <div class="flex shrink-0 items-center gap-2 lg:w-36">
                <span class="text-xs font-bold text-slate-700">Filter</span>
                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[9px] font-semibold text-amber-700">Belum aktif</span>
            </div>
            <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-3" aria-disabled="true">
                <label class="flex items-center gap-2">
                    <span class="w-12 shrink-0 text-[10px] font-medium text-slate-500 sm:sr-only">Periode</span>
                    <select disabled aria-label="Periode" class="h-8 w-full cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-2 text-xs text-slate-500 opacity-100"><option>{{ $context['filters']['period'] }}</option></select>
                </label>
                <label class="flex items-center gap-2">
                    <span class="w-12 shrink-0 text-[10px] font-medium text-slate-500 sm:sr-only">Shift</span>
                    <select disabled aria-label="Shift" class="h-8 w-full cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-2 text-xs text-slate-500 opacity-100"><option>Shift {{ $context['filters']['shift'] }}</option></select>
                </label>
                <label class="flex items-center gap-2">
                    <span class="w-12 shrink-0 text-[10px] font-medium text-slate-500 sm:sr-only">Posko</span>
                    <select disabled aria-label="Posko" class="h-8 w-full cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-2 text-xs text-slate-500 opacity-100"><option>Semua Posko aktif</option></select>
                </label>
            </div>
            <p class="text-[10px] leading-tight text-slate-400 lg:max-w-64">{{ $context['filters']['reason'] }}</p>
        </div>
    </section>

    <section aria-labelledby="kpi-heading">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 id="kpi-heading" class="text-base font-bold text-slate-900">Ringkasan Utama</h2>
            <p class="text-[10px] text-slate-500">Tanda — berarti data transaksi belum tersedia, bukan bernilai 0.</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($kpis as $key => $metric)
                <article class="group relative flex min-h-28 flex-col rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm transition {{ $metric['navigation']['available'] ? 'hover:border-blue-300 hover:shadow-md' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-[11px] font-bold uppercase tracking-wide text-slate-600">{{ $metric['title'] }}</h3>
                            <p class="mt-0.5 text-[10px] leading-snug text-slate-400">{{ $metric['description'] }}</p>
                        </div>
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[9px] font-bold text-slate-500" aria-hidden="true">{{ $kpiAbbreviations[$key] }}</span>
                    </div>
                    <div class="mt-auto flex items-end justify-between gap-3 pt-2">
                        <span class="text-2xl font-bold leading-none text-slate-300">—</span>
                        @if($metric['navigation']['available'])
                            <span class="text-right">
                                <span class="block text-[10px] font-semibold text-blue-700 group-hover:text-blue-900">{{ $metric['navigation']['label'] }} →</span>
                                <span class="block text-[9px] text-slate-400">Tanpa filter Dashboard</span>
                            </span>
                        @else
                            <span class="rounded bg-amber-50 px-2 py-1 text-[9px] font-semibold text-amber-700">{{ $metric['status'] }}</span>
                        @endif
                    </div>
                    @if($metric['navigation']['available'])
                        <a href="{{ route($metric['navigation']['route_name']) }}" class="absolute inset-0 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" aria-label="{{ $metric['navigation']['label'] }}"><span class="sr-only">{{ $metric['navigation']['label'] }}</span></a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="team-heading">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 id="team-heading" class="text-base font-bold text-slate-900">Ringkasan Empat Tim</h2>
            <p class="text-[10px] text-slate-500">Angka transaksi menunggu modul operasional.</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($teamSummaries as $team)
                <article class="flex flex-col rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-bold text-slate-900">{{ $team['name'] }}</h3>
                        <span class="rounded bg-blue-50 px-2 py-0.5 text-[9px] font-medium text-blue-700">{{ $team['master_context'] }}</span>
                    </div>
                    <p class="mt-1 text-[10px] leading-snug text-slate-500">{{ $team['description'] }}</p>
                    <dl class="mt-2 grid grid-cols-2 gap-1.5">
                        @foreach($team['metrics'] as $metric)
                            <div class="rounded-md bg-slate-50 px-2 py-1.5">
                                <dt class="truncate text-[10px] font-semibold text-slate-700">{{ $metric['label'] }}</dt>
                                <dd class="mt-0.5 truncate text-[9px] text-slate-400">— · {{ $metric['detail'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-2 text-[10px]">
                        @if($team['route_name'])
                            <a href="{{ route($team['route_name']) }}" class="relative z-10 font-semibold text-blue-700 hover:text-blue-900">{{ $team['route_label'] }} →</a>
                        @else
                            <span class="font-medium text-slate-400" aria-disabled="true">Halaman tim belum tersedia</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="actions-heading">
        <div class="flex flex-col gap-2 border-b border-slate-100 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <h2 id="actions-heading" class="text-sm font-bold text-slate-900">Tindakan Admin</h2>
                <span class="text-[10px] text-slate-400">Data transaksi belum tersedia</span>
            </div>
            <p class="text-[9px] text-slate-500">Reschedule biasa tidak otomatis menjadi approval Admin.</p>
        </div>
        <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4">
            @foreach($adminActionSummaries as $action)
                <article class="flex items-start justify-between gap-2 px-4 py-3">
                    <div>
                        <h3 class="text-[11px] font-bold text-slate-800">{{ $action['title'] }}</h3>
                        <p class="mt-0.5 text-[9px] leading-snug text-slate-500">{{ $action['description'] }}</p>
                    </div>
                    <span class="shrink-0 text-sm font-bold text-slate-300">—</span>
                </article>
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="posko-heading">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/70 px-4 py-3">
            <div>
                <h2 id="posko-heading" class="text-sm font-bold text-slate-900">Monitoring Posko</h2>
                <p class="mt-0.5 text-[10px] text-slate-500">Target 6 WO APKUK berlaku per Posko per shift; realisasi menunggu transaksi.</p>
            </div>
            <span class="shrink-0 rounded-full bg-blue-100 px-2.5 py-1 text-[9px] font-bold text-blue-700">{{ count($poskoStatuses) }} Posko aktif</span>
        </div>

        <div class="hidden md:block">
            <table class="w-full table-fixed text-left">
                <thead class="border-b border-slate-200 bg-slate-50 text-[9px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="w-[18%] px-3 py-2 font-semibold">Posko</th>
                        <th class="w-[12%] px-3 py-2 font-semibold">Shift</th>
                        <th class="w-[25%] px-3 py-2 font-semibold">WO APKUK</th>
                        <th class="w-[13%] px-3 py-2 font-semibold">PASILA</th>
                        <th class="w-[13%] px-3 py-2 font-semibold">P0</th>
                        <th class="w-[19%] px-3 py-2 font-semibold">Perhatian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($poskoStatuses as $posko)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-3 py-2.5"><div class="truncate text-xs font-semibold text-slate-900" title="{{ $posko['name'] }}">{{ $posko['name'] }}</div><div class="text-[9px] text-slate-400">{{ $posko['code'] }}</div></td>
                            <td class="px-3 py-2.5"><span class="rounded bg-blue-50 px-1.5 py-1 text-[10px] font-semibold text-blue-700">{{ $posko['shift_code'] }} · {{ $posko['shift_name'] }}</span></td>
                            <td class="px-3 py-2.5"><div class="text-[10px] font-semibold text-slate-700">Realisasi — / Target {{ $posko['apkuk_target'] }}</div><div class="text-[9px] text-amber-600">Data realisasi belum tersedia</div></td>
                            <td class="px-3 py-2.5 text-[10px] text-slate-400">Belum tersedia</td>
                            <td class="px-3 py-2.5 text-[10px] text-slate-400">Belum tersedia</td>
                            <td class="px-3 py-2.5 text-[10px] text-slate-400">Belum dapat dinilai</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-xs text-slate-500">Belum ada Posko aktif pada master.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 md:hidden">
            @forelse($poskoStatuses as $posko)
                <article class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                    <div class="flex items-start justify-between gap-2"><div><h3 class="text-xs font-bold text-slate-900">{{ $posko['name'] }}</h3><p class="text-[9px] text-slate-400">{{ $posko['code'] }}</p></div><span class="rounded bg-blue-100 px-1.5 py-1 text-[9px] font-semibold text-blue-700">Shift {{ $posko['shift_code'] }}</span></div>
                    <p class="mt-2 text-[10px] font-semibold text-slate-700">Realisasi — / Target {{ $posko['apkuk_target'] }}</p>
                    <p class="mt-1 text-[9px] text-amber-600">PASILA, P0, dan perhatian: data belum tersedia.</p>
                </article>
            @empty
                <p class="py-6 text-center text-xs text-slate-500 sm:col-span-2">Belum ada Posko aktif pada master.</p>
            @endforelse
        </div>
    </section>

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                <div><h2 class="text-sm font-bold text-slate-900">Aktivitas Terbaru</h2><p class="text-[10px] text-slate-500">Data aktivitas belum tersedia</p></div>
                <svg class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </summary>
            <div class="border-t border-slate-100 px-4 py-3">
                @if($recentActivities['available'])
                    @forelse($recentActivities['items'] as $activity)
                        <article class="py-2 text-xs text-slate-700">{{ $activity['summary'] }}</article>
                    @empty
                        <p class="text-xs text-slate-500">Belum ada aktivitas pada periode ini.</p>
                    @endforelse
                @else
                    <p class="text-xs leading-relaxed text-slate-500">{{ $recentActivities['reason'] }}</p>
                @endif
            </div>
        </details>

        <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Status Data dan Peta</h2>
                    <p class="text-[10px] text-emerald-700">{{ $dataReadiness['masters']['active_poskos'] }} Posko · {{ $dataReadiness['masters']['active_penyulangs'] }} Penyulang dari master{{ $dataReadiness['map']['available'] ? ' · Peta Jaringan aktif' : '' }}</p>
                </div>
                <svg class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </summary>
            <div class="grid gap-2 border-t border-slate-100 p-3 sm:grid-cols-3">
                @foreach(['transactions', 'network', 'map'] as $readinessKey)
                    @php($readiness = $dataReadiness[$readinessKey])
                    <div class="rounded-lg border p-3 {{ $readiness['available'] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
                        <h3 class="text-[10px] font-bold {{ $readiness['available'] ? 'text-emerald-900' : 'text-amber-900' }}">{{ $readiness['label'] }}</h3>
                        <p class="mt-1 text-[9px] leading-snug {{ $readiness['available'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $readiness['description'] }}</p>
                        @if($readiness['route_name'])
                            <a href="{{ route($readiness['route_name']) }}" class="mt-1.5 inline-block text-[10px] font-semibold text-emerald-800 hover:text-emerald-950">{{ $readiness['route_label'] }} →</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    </div>
</div>
@endsection
