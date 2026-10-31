@extends('layouts.admin')

@section('title', 'P0')

@section('content')
@php
    use App\Models\P0Item;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $stageTone = [
        P0Item::STAGE_MENUNGGU_ACC => 'amber',
        P0Item::STAGE_MENUNGGU_PENGGANTI => 'red',
        P0Item::STAGE_DIGANTI => 'slate',
        P0Item::STAGE_AKTIF => 'blue',
        P0Item::STAGE_MENUNGGU_VERIFIKASI => 'violet',
        P0Item::STAGE_SELESAI => 'emerald',
        P0Item::STAGE_TIDAK_DILANJUTKAN => 'slate',
    ];
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="P0 POSKO"
        state="partial"
        description="P0 adalah pekerjaan POSKO yang selalu terkait sesi PASILA dan sumber masalah yang tercatat (temuan PASILA atau temuan inspeksi tervalidasi) pada Section milik Posko. Status P0 terpisah dari WO APKUK dan tidak termasuk kuota enam WO APKUK."
    />

    <x-admin.notice tone="warning" title="Aturan P0:">
        Usulan SYSTEM wajib di-ACC Admin sebelum menjadi pekerjaan; usulan yang ditolak menimbulkan kewajiban P0 manual pengganti dalam sesi PASILA yang sama.
        Target minimal {{ \App\Services\Pasila\P0Service::TARGET_PER_SHIFT }} P0 per Posko per shift terpenuhi hanya oleh P0 yang <strong>selesai</strong> (laporan dengan bukti dan verifikasi).
        Usulan SYSTEM otomatis: <strong>{{ $strategy->notReadyReason() ?? ($strategy->name().' '.$strategy->version()) }}</strong>
    </x-admin.notice>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach ([P0Item::STAGE_MENUNGGU_ACC => 'amber', P0Item::STAGE_MENUNGGU_PENGGANTI => 'red', P0Item::STAGE_AKTIF => 'blue', P0Item::STAGE_MENUNGGU_VERIFIKASI => 'violet', P0Item::STAGE_SELESAI => 'emerald'] as $key => $tone)
            <x-admin.stat-card :label="P0Item::STAGE_LABELS[$key]" :value="$load->failed('items') ? null : ($counts[$key] ?? 0)" :tone="$tone" :href="route('admin.p0.index', ['stage' => $key])" unavailable-label="Gagal dimuat" />
        @endforeach
    </div>

    <x-admin.panel flush title="Daftar P0" :description="'Maksimal '.$limit.' P0 terbaru. Tahap dihitung dari persetujuan, kewajiban pengganti, dan status WO P0.'">
        <x-admin.filter-bar :action="route('admin.p0.index')" :reset-url="route('admin.p0.index')" :active="request()->hasAny(['stage', 'posko_id'])">
            <x-admin.filter-select name="stage" label="Tahap" :options="$stageOptions" :value="$filters['stage']" placeholder="Semua tahap" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko" />
        </x-admin.filter-bar>
        @if ($load->failed('items'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[1100px]">
                <x-slot:head>
                    <th class="px-4 py-3">P0</th>
                    <th class="px-4 py-3">Sesi PASILA</th>
                    <th class="px-4 py-3">Posko · Section</th>
                    <th class="px-4 py-3">Asal &amp; sumber</th>
                    <th class="px-4 py-3">Tahap</th>
                    <th class="px-4 py-3">WO P0</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($items as $item)
                    <tr class="hover:bg-slate-50/60" data-p0="{{ $item->code }}" data-stage="{{ $item->stage() }}">
                        <td class="px-4 py-3"><div class="text-xs font-bold text-slate-900">{{ $item->code }}</div><div class="text-[10px] text-slate-500">{{ $item->created_at->timezone($tz)->format('d/m/Y H:i') }} WIB</div></td>
                        <td class="px-4 py-3 text-xs">
                            <a href="{{ route('admin.pasila.show', ['posko' => $item->posko_id, 'work_date' => $item->session->shiftInstance->operational_date->toDateString(), 'shift_code' => $item->session->shiftInstance->shift_code]) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->session->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-xs"><div class="font-semibold text-slate-800">{{ $item->posko->nama_posko }}</div><div class="text-slate-500">{{ $item->section_name }} ({{ $item->penyulang->kode_penyulang }})</div></td>
                        <td class="px-4 py-3 text-xs"><div>{{ $item->originLabel() }}</div><div class="text-slate-500">{{ $item->sourceLabel() }} · {{ $item->source_snapshot['finding_code'] ?? '-' }}</div></td>
                        <td class="px-4 py-3"><x-admin.status-badge :tone="$stageTone[$item->stage()]">{{ $item->stageLabel() }}</x-admin.status-badge></td>
                        <td class="px-4 py-3 text-xs">
                            @if ($item->workOrder)
                                <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->workOrder->code }}</a>
                                <div class="text-slate-500">{{ $item->workOrder->status->label() }}</div>
                            @else
                                <span class="text-slate-400">Belum ada (bukan pekerjaan)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center"><a href="{{ route('admin.p0.show', $item) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-admin.empty-state title="Belum ada P0." description="P0 dibuat dari sesi PASILA berdasarkan sumber masalah yang tercatat. Tidak ada P0 yang dibuat tanpa sumber." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>
</div>
@endsection
