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
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        title="Analitik"
        state="partial"
        :description="'Ringkasan baca saja dari data yang tersedia di database, periode '.$fromLabel.' – '.$toLabel.' (tanggal WIB).'"
    />

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.analytics.index')" :reset-url="route('admin.analytics.index')" :active="$hasFilter" note="Periode berlaku untuk WO (tanggal kerja) dan gangguan (tanggal kejadian). APKUK dan Keypoint menampilkan kondisi sumber saat ini.">
            <x-admin.filter-select name="period" label="Periode" :options="\App\Http\Controllers\Admin\AnalyticsController::PERIODS" :value="$period" :allow-empty="false" />
        </x-admin.filter-bar>
    </x-admin.panel>

    <x-admin.panel flush title="Work Order per tim dan status" description="Jumlah WO dengan tanggal kerja dalam periode. Kolom bernilai 0 berarti memang tidak ada WO dengan status tersebut.">
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

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel flush title="Gangguan per bulan" description="Tanggal kejadian dalam periode; bulan tanpa gangguan tidak ditampilkan.">
            @if ($load->failed('gangguan_month'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[420px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Bulan</th>
                        <th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                        <th class="px-4 py-3 text-right">Gangguan</th>
                    </x-slot:head>
                    @forelse ($gangguanPerMonth as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m', $row->bulan)->format('m/Y') }}</td>
                            <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxMonth" :label="$row->total.' gangguan'" /></td>
                            <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-admin.empty-state title="Tidak ada gangguan pada periode ini." /></td></tr>
                    @endforelse
                </x-admin.table>
            @endif
        </x-admin.panel>

        <x-admin.panel flush title="Penyulang dengan gangguan terbanyak" description="10 teratas; frekuensi mentah tanpa ambang Special Case.">
            <x-slot:actions>
                <a href="{{ route('admin.special-case.index', ['period' => $period]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Buka Special Case →</a>
            </x-slot:actions>
            @if ($load->failed('gangguan_penyulang'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[420px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Penyulang</th>
                        <th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                        <th class="px-4 py-3 text-right">Gangguan</th>
                    </x-slot:head>
                    @forelse ($gangguanPerPenyulang as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-xs font-semibold"><a href="{{ route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => (string) $row->penyulang, 'period' => $period]) }}" class="text-blue-600 hover:text-blue-800">{{ filled($row->penyulang) ? $row->penyulang : '(kosong)' }}</a></td>
                            <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxPenyulang" :label="$row->total.' gangguan'" /></td>
                            <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-admin.empty-state title="Tidak ada gangguan pada periode ini." /></td></tr>
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
                        <th class="px-4 py-3">Penyebab</th>
                        <th class="w-1/2 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                        <th class="px-4 py-3 text-right">Gangguan</th>
                    </x-slot:head>
                    @forelse ($gangguanPerPenyebab as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->penyebab) ? $row->penyebab : '(kosong)' }}</td>
                            <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxPenyebab" :label="$row->total.' gangguan'" /></td>
                            <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-admin.empty-state title="Tidak ada gangguan pada periode ini." /></td></tr>
                    @endforelse
                </x-admin.table>
            @endif
        </x-admin.panel>

        <x-admin.panel flush title="Status APKUK sumber" description="Baris apkuks aktif (bukan histori hilang-sumber); seluruh periode. Kurang = total kurang_bh.">
            @if ($load->failed('apkuks'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[480px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Status sumber</th>
                        <th class="w-2/5 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                        <th class="px-4 py-3 text-right">Baris</th>
                        <th class="px-4 py-3 text-right">Kurang (bh)</th>
                    </x-slot:head>
                    @forelse ($apkukStatus as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->status) ? $row->status : '(kosong)' }}</td>
                            <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxApkuk" :label="$row->total.' baris'" /></td>
                            <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ number_format($row->total, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right text-xs">{{ $row->kurang !== null ? number_format((float) $row->kurang, 0, ',', '.') : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-admin.empty-state title="Belum ada data APKUK sumber." /></td></tr>
                    @endforelse
                </x-admin.table>
            @endif
        </x-admin.panel>

        <x-admin.panel flush title="Keypoint per jenis" description="Seluruh keypoints; berkoordinat = latitude dan longitude terisi.">
            @if ($load->failed('keypoints'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[480px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Jenis</th>
                        <th class="w-2/5 px-4 py-3"><span class="sr-only">Proporsi</span></th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-right">Berkoordinat</th>
                    </x-slot:head>
                    @forelse ($keypointJenis as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-xs font-semibold text-slate-800">{{ filled($row->jenis) ? $row->jenis : '(kosong)' }}</td>
                            <td class="px-4 py-2.5"><x-admin.bar :value="(int) $row->total" :max="$maxKeypoint" :label="$row->total.' keypoint'" /></td>
                            <td class="px-4 py-2.5 text-right font-bold text-slate-800">{{ $row->total }}</td>
                            <td class="px-4 py-2.5 text-right text-xs">{{ (int) $row->mapped }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-admin.empty-state title="Belum ada data Keypoint." /></td></tr>
                    @endforelse
                </x-admin.table>
            @endif
        </x-admin.panel>

        <x-admin.unavailable-panel
            title="KPI PASILA, P0, Inspeksi & ROW"
            description="Realisasi PASILA per shift, P0 per shift, temuan inspeksi per hari, dan risiko vegetasi belum dapat dihitung karena domainnya belum tersedia."
            :requirements="['Domain PASILA/P0 dan Inspeksi', 'Formula prioritas dan risiko (TBD)']"
        />
    </div>
</div>
@endsection
