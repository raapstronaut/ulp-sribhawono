@extends('layouts.admin')

@section('title', 'Analitik')

@section('content')
@php
    $teamRoutes = [
        \App\Models\WorkOrder::TEAM_POSKO => 'admin.work-orders.index',
        \App\Models\WorkOrder::TEAM_HARSUS => 'admin.harsus.index',
        \App\Models\WorkOrder::TEAM_ROW => 'admin.row.index',
    ];
    $fromLabel = \Illuminate\Support\Carbon::parse($range['from'])->format('d/m/Y');
    $toLabel = \Illuminate\Support\Carbon::parse($range['to'])->format('d/m/Y');
    $maxMonth = (int) ($gangguanPerMonth->max('total') ?? 0);
    $maxPenyulang = (int) ($gangguanPerPenyulang->max('total') ?? 0);
    $maxPenyebab = (int) ($gangguanPerPenyebab->max('total') ?? 0);
    $maxApkuk = (int) ($apkukStatus->max('total') ?? 0);
    $maxKeypoint = (int) ($keypointJenis->max('total') ?? 0);
    $analyticsTabs = [
        'ringkasan' => 'Ringkasan',
        'wo' => 'Work Order',
        'gangguan' => 'Gangguan',
        'sumber' => 'APKUK & Aset',
    ];
@endphp

<style>
    [data-analytics-panel][hidden] { display: none !important; }
    [data-analytics-tab][aria-selected="true"] { background:#2563eb; color:#fff; border-color:#2563eb; }
    [data-analytics-tab][aria-selected="false"] { background:#fff; color:#1d4ed8; border-color:#dbeafe; }
</style>

<div class="mx-auto max-w-7xl space-y-3">
    <x-admin.page-header
        section="Data & Admin"
        title="Analitik"
        state="live"
        :description="'Statistik data operasional, '.$fromLabel.' – '.$toLabel.' (WIB).'"
    >
        <x-slot:actions>
            <x-admin.button variant="secondary" :href="route('admin.analytics.export', request()->only(['period', 'team_type', 'posko_id', 'penyulang_id']))">Ekspor CSV laporan WO</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel flush>
        <x-admin.filter-bar
            :action="route('admin.analytics.index')"
            :reset-url="route('admin.analytics.index')"
            :active="$hasFilter"
            note="Periode berlaku untuk WO dan gangguan. Filter tim/POSKO/penyulang hanya untuk statistik WO. APKUK dan Keypoint menampilkan kondisi sumber saat ini."
        >
            <x-admin.filter-select name="period" label="Periode" :options="\App\Http\Controllers\Admin\AnalyticsController::PERIODS" :value="$period" :allow-empty="false" />
            <x-admin.filter-select name="team_type" label="Tim" :options="\App\Models\WorkOrder::TEAM_LABELS" :value="$woFilters['team_type']" placeholder="Semua tim" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$woFilters['posko_id']" placeholder="Semua Posko" />
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$woFilters['penyulang_id']" placeholder="Semua penyulang" />
        </x-admin.filter-bar>
    </x-admin.panel>

    <div class="rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Kategori analitik">
            @foreach ($analyticsTabs as $id => $label)
                <button type="button"
                        id="analytics-tab-{{ $id }}"
                        data-analytics-tab="{{ $id }}"
                        role="tab"
                        aria-controls="analytics-panel-{{ $id }}"
                        aria-selected="{{ $id === 'ringkasan' ? 'true' : 'false' }}"
                        class="rounded-lg border px-4 py-2 text-sm font-semibold transition-colors hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <section id="analytics-panel-ringkasan" data-analytics-panel="ringkasan" role="tabpanel" aria-labelledby="analytics-tab-ringkasan" class="space-y-3">
        @if ($load->failed('wo_stats'))
            <x-admin.error-state :inset="false" />
        @else
            @php $perf = $woStats['performance']; @endphp
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-admin.stat-card label="WO dalam periode" :value="$perf['total']" :hint="$perf['active'].' aktif · '.$perf['cancelled'].' dibatalkan'" />
                <x-admin.stat-card label="Selesai" :value="$perf['completed']" :hint="$perf['on_time'].' tepat waktu · '.$perf['late_completed'].' terlambat'" tone="emerald" />
                <x-admin.stat-card label="Ketepatan waktu" :value="$perf['on_time_rate'] === null ? null : number_format($perf['on_time_rate'], 1, ',', '.').'%'" hint="Dari WO selesai yang punya batas waktu" unavailable-label="Belum ada WO selesai" />
                <x-admin.stat-card label="Terlambat (masih aktif)" :value="$perf['overdue_active']" :tone="$perf['overdue_active'] > 0 ? 'red' : 'slate'" :hint="'Rata-rata durasi selesai '.($perf['avg_hours'] === null ? '—' : number_format($perf['avg_hours'], 1, ',', '.').' jam')" />
            </div>
        @endif
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-slate-700">
            <span class="font-semibold text-slate-900">Gunakan tab untuk detail.</span>
            Work Order berisi rekap pekerjaan dan status; Gangguan berisi tren dan penyebab; APKUK & Aset berisi kondisi sumber.
            Angka sumber tidak disamakan dengan jumlah Work Order.
        </div>
        <x-admin.unavailable-panel
            title="KPI PASILA, P0, Inspeksi & ROW"
            description="Realisasi PASILA per shift, P0 per shift, temuan inspeksi per hari, dan risiko vegetasi belum dapat dihitung oleh panel analitik ini."
            :requirements="['Agregasi domain PASILA/P0 dan Inspeksi', 'Formula prioritas dan risiko (TBD)']"
        />
    </section>

    <section id="analytics-panel-wo" data-analytics-panel="wo" role="tabpanel" aria-labelledby="analytics-tab-wo" class="space-y-3" hidden>
        @if ($load->failed('wo_stats'))
            <x-admin.error-state :inset="false" />
        @else
            @foreach (['by_period' => 'Rekap per periode ('.$woStats['period_granularity'].')', 'by_work_type' => 'Rekap per jenis pekerjaan', 'by_posko' => 'Rekap per Posko', 'by_penyulang' => 'Rekap per penyulang'] as $key => $title)
                <details class="rounded-xl border border-slate-200 bg-white shadow-sm" {{ $key === 'by_period' ? 'open' : '' }}>
                    <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-slate-800">{{ $title }} <span class="ml-1 text-xs font-normal text-slate-500">({{ count($woStats[$key]) }} kelompok)</span></summary>
                    <div class="border-t border-slate-100">
                        @if ($woStats[$key] === [])
                            <p class="px-4 py-4 text-sm text-slate-500">Tidak ada WO pada periode/filter ini.</p>
                        @else
                            <x-admin.table min-width="min-w-[760px]">
                                <x-slot:head>
                                    <th class="px-4 py-3">{{ $key === 'by_period' ? 'Periode' : 'Kelompok' }}</th>
                                    <th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Aktif</th>
                                    <th class="px-4 py-3 text-right">Selesai</th><th class="px-4 py-3 text-right">Tepat waktu</th>
                                    <th class="px-4 py-3 text-right">Terlambat aktif</th><th class="px-4 py-3 text-right">Ketepatan</th>
                                    <th class="px-4 py-3 text-right">Rata-rata jam</th>
                                </x-slot:head>
                                @foreach ($woStats[$key] as $label => $m)
                                    <tr>
                                        <td class="px-4 py-2 text-xs font-semibold">{{ $label }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['total'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['active'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['completed'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['on_time'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs {{ $m['overdue_active'] > 0 ? 'text-red-600 font-semibold' : '' }}">{{ $m['overdue_active'] }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['on_time_rate'] === null ? '—' : number_format($m['on_time_rate'], 1, ',', '.').'%' }}</td>
                                        <td class="px-4 py-2 text-right text-xs">{{ $m['avg_hours'] === null ? '—' : number_format($m['avg_hours'], 1, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </x-admin.table>
                        @endif
                    </div>
                </details>
            @endforeach
        @endif

        <x-admin.panel flush title="Work Order per tim dan status" description="Jumlah WO menurut tanggal kerja pada periode terpilih.">
            @if ($load->failed('work_orders'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[960px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Tim</th>
                        @foreach ($statuses as $status)
                            <th class="px-3 py-3 text-right">{{ $status->label() }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-right">Total</th>
                    </x-slot:head>
                    @foreach ($teamRoutes as $teamType => $routeName)
                        @php($teamRow = $workOrderMatrix[$teamType] ?? [])
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route($routeName) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $teamType }}</a></td>
                            @foreach ($statuses as $status)
                                <td class="px-3 py-3 text-right {{ ($teamRow[$status->value] ?? 0) > 0 ? 'font-semibold text-slate-800' : 'text-slate-400' }}">{{ $teamRow[$status->value] ?? 0 }}</td>
                            @endforeach
                            <td class="px-4 py-3 text-right font-bold text-slate-900">{{ array_sum($teamRow) }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.panel>
    </section>

    <section id="analytics-panel-gangguan" data-analytics-panel="gangguan" role="tabpanel" aria-labelledby="analytics-tab-gangguan" class="space-y-3" hidden>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <x-admin.panel flush title="Gangguan per bulan" description="Bulan tanpa kejadian tidak ditampilkan.">
                @if ($load->failed('gangguan_month'))
                    <x-admin.error-state />
                @else
                    <x-admin.table min-width="min-w-[420px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Bulan</th><th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th><th class="px-4 py-3 text-right">Gangguan</th>
                        </x-slot:head>
                        @forelse ($gangguanPerMonth as $row)
                            <tr>
                                <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m', $row->bulan)->format('m/Y') }}</td>
                                <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxMonth" :label="$row->total.' gangguan'" /></td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-sm text-slate-500">Tidak ada gangguan pada periode ini.</td></tr>
                        @endforelse
                    </x-admin.table>
                @endif
            </x-admin.panel>

            <x-admin.panel flush title="Penyulang dengan gangguan terbanyak" description="10 teratas, frekuensi mentah tanpa ambang Special Case.">
                <x-slot:actions>
                    <a href="{{ route('admin.special-case.index', ['period' => $period]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Buka Special Case →</a>
                </x-slot:actions>
                @if ($load->failed('gangguan_penyulang'))
                    <x-admin.error-state />
                @else
                    <x-admin.table min-width="min-w-[420px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Penyulang</th><th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th><th class="px-4 py-3 text-right">Gangguan</th>
                        </x-slot:head>
                        @forelse ($gangguanPerPenyulang as $row)
                            <tr>
                                <td class="px-4 py-2.5 text-xs font-semibold"><a href="{{ route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => (string) $row->penyulang, 'period' => $period]) }}" class="text-blue-600 hover:text-blue-800">{{ filled($row->penyulang) ? $row->penyulang : '(kosong)' }}</a></td>
                                <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxPenyulang" :label="$row->total.' gangguan'" /></td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-sm text-slate-500">Tidak ada gangguan pada periode ini.</td></tr>
                        @endforelse
                    </x-admin.table>
                @endif
            </x-admin.panel>

            <x-admin.panel flush title="Penyebab gangguan" description="10 penyebab teratas sesuai teks sumber.">
                @if ($load->failed('gangguan_penyebab'))
                    <x-admin.error-state />
                @else
                    <x-admin.table min-width="min-w-[420px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Penyebab</th><th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th><th class="px-4 py-3 text-right">Gangguan</th>
                        </x-slot:head>
                        @forelse ($gangguanPerPenyebab as $row)
                            <tr>
                                <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->penyebab) ? $row->penyebab : '(kosong)' }}</td>
                                <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxPenyebab" :label="$row->total.' gangguan'" /></td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-sm text-slate-500">Tidak ada gangguan pada periode ini.</td></tr>
                        @endforelse
                    </x-admin.table>
                @endif
            </x-admin.panel>
        </div>
    </section>

    <section id="analytics-panel-sumber" data-analytics-panel="sumber" role="tabpanel" aria-labelledby="analytics-tab-sumber" class="space-y-3" hidden>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <x-admin.panel flush title="Status APKUK sumber" description="Laporan aktif dari snapshot terbaru. Pending dan histori tidak dihitung; kurang = total kurang_bh.">
                @if ($load->failed('apkuks'))
                    <x-admin.error-state />
                @else
                    <x-admin.table min-width="min-w-[480px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Status sumber</th><th class="w-2/5 px-4 py-3"><span class="sr-only">Proporsi</span></th><th class="px-4 py-3 text-right">Baris</th><th class="px-4 py-3 text-right">Kurang (bh)</th>
                        </x-slot:head>
                        @forelse ($apkukStatus as $row)
                            <tr>
                                <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->status) ? $row->status : '(kosong)' }}</td>
                                <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxApkuk" :label="$row->total.' baris'" /></td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ number_format($row->total, 0, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-right text-xs">{{ $row->kurang !== null ? number_format((float) $row->kurang, 0, ',', '.') : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-sm text-slate-500">Belum ada data APKUK sumber.</td></tr>
                        @endforelse
                    </x-admin.table>
                @endif
            </x-admin.panel>

            <x-admin.panel flush title="Keypoint per jenis" description="Seluruh keypoint; berkoordinat = latitude dan longitude terisi.">
                @if ($load->failed('keypoints'))
                    <x-admin.error-state />
                @else
                    <x-admin.table min-width="min-w-[480px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Jenis</th><th class="w-2/5 px-4 py-3"><span class="sr-only">Proporsi</span></th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Berkoordinat</th>
                        </x-slot:head>
                        @forelse ($keypointJenis as $row)
                            <tr>
                                <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->jenis) ? $row->jenis : '(kosong)' }}</td>
                                <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxKeypoint" :label="$row->total.' keypoint'" /></td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                                <td class="px-4 py-2.5 text-right text-xs">{{ (int) $row->mapped }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-sm text-slate-500">Belum ada data Keypoint.</td></tr>
                        @endforelse
                    </x-admin.table>
                @endif
            </x-admin.panel>
        </div>
    </section>
</div>

<script>
(() => {
    const tabs = [...document.querySelectorAll('[data-analytics-tab]')];
    const panels = [...document.querySelectorAll('[data-analytics-panel]')];
    if (!tabs.length || !panels.length) return;
    const names = new Set(tabs.map(tab => tab.dataset.analyticsTab));
    const params = new URLSearchParams(window.location.search);

    function activate(name, updateUrl = true) {
        if (!names.has(name)) name = 'ringkasan';
        tabs.forEach(tab => {
            const current = tab.dataset.analyticsTab === name;
            tab.setAttribute('aria-selected', String(current));
            tab.tabIndex = current ? 0 : -1;
        });
        panels.forEach(panel => {
            panel.hidden = panel.dataset.analyticsPanel !== name;
        });
        if (updateUrl) {
            const next = new URL(window.location.href);
            if (name === 'ringkasan') next.searchParams.delete('tab');
            else next.searchParams.set('tab', name);
            window.history.replaceState(null, '', next.pathname + next.search + next.hash);
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activate(tab.dataset.analyticsTab));
        tab.addEventListener('keydown', event => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
            event.preventDefault();
            const idx = tabs.indexOf(tab);
            const dir = event.key === 'ArrowRight' ? 1 : -1;
            const next = tabs[(idx + dir + tabs.length) % tabs.length];
            next.focus();
            activate(next.dataset.analyticsTab);
        });
    });

    document.querySelectorAll('form').forEach(form => {
        if (form.method.toLowerCase() !== 'get') return;
        form.addEventListener('submit', () => {
            const existing = form.querySelector('input[name="tab"]');
            if (existing) existing.remove();
            const current = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.analyticsTab;
            if (!current || current === 'ringkasan') return;
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = 'tab'; field.value = current;
            form.appendChild(field);
        });
    });

    activate(params.get('tab') || 'ringkasan', false);
})();
</script>
@endsection