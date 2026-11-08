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
    $pendingAcc = $counts[P0Item::STAGE_MENUNGGU_ACC] ?? 0;
    $pendingReplacement = $counts[P0Item::STAGE_MENUNGGU_PENGGANTI] ?? 0;
    $pendingVerification = $counts[P0Item::STAGE_MENUNGGU_VERIFIKASI] ?? 0;
    $actionCount = $pendingAcc + $pendingReplacement + $pendingVerification;
    $countAll = array_sum($counts);
@endphp

<div class="mx-auto max-w-7xl space-y-3" data-p0-monitor-redesign="v2">
    <x-admin.posko-tabs />

    <x-admin.page-header
        section="Pekerjaan"
        title="P0 POSKO"
        state="partial"
        description="Pantau persetujuan, pekerjaan, dan penyelesaian P0 yang berasal dari sumber masalah sah pada sesi PASILA."
    >
        <x-slot:actions>
            <a href="{{ route('admin.pasila.index') }}"
               class="inline-flex items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">
                Buka PASILA
            </a>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Informasi operasional diletakkan lebih dulu; alur panjang dipindah ke detail lipat. --}}
    <section aria-label="Ringkasan tahap P0" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2 py-1 text-[10px] font-bold uppercase text-white">Monitor P0</span>
                <span class="font-semibold text-slate-700">
                    {{ $load->failed('items') ? '—' : $countAll }} P0 tercatat
                    {{ $filters['posko_id'] !== '' ? 'untuk POSKO terpilih' : 'dalam cakupan daftar' }}
                </span>
            </div>
            <span class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 font-semibold text-amber-800">
                {{ $load->failed('items') ? '—' : $actionCount }} perlu tindakan Admin/verifikasi
            </span>
        </div>

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-5">
            @foreach ([
                P0Item::STAGE_MENUNGGU_ACC => 'amber',
                P0Item::STAGE_MENUNGGU_PENGGANTI => 'red',
                P0Item::STAGE_AKTIF => 'blue',
                P0Item::STAGE_MENUNGGU_VERIFIKASI => 'violet',
                P0Item::STAGE_SELESAI => 'emerald',
            ] as $key => $tone)
                <x-admin.stat-card
                    :label="P0Item::STAGE_LABELS[$key]"
                    :value="$load->failed('items') ? null : ($counts[$key] ?? 0)"
                    :tone="$tone"
                    :href="route('admin.p0.index', ['stage' => $key, 'posko_id' => $filters['posko_id']])"
                    unavailable-label="Gagal dimuat"
                />
            @endforeach
        </div>
    </section>

    <x-admin.panel flush title="Daftar P0" :description="'Maksimal '.$limit.' P0 terbaru; pilih tahap atau POSKO untuk mempersempit daftar. Status P0 dan status WO APKUK berbeda.'">
        <x-admin.filter-bar
            :action="route('admin.p0.index')"
            :reset-url="route('admin.p0.index')"
            :active="request()->hasAny(['stage', 'posko_id'])"
        >
            <x-admin.filter-select name="stage" label="Tahap" :options="$stageOptions" :value="$filters['stage']" placeholder="Semua tahap" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko" />
        </x-admin.filter-bar>

        @if ($load->failed('items'))
            <x-admin.error-state />
        @else
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-600">
                <span>
                    <strong class="text-slate-900">{{ $items->count() }} P0 ditampilkan</strong>
                    {{ $filters['stage'] !== '' ? ' · Tahap: '.(P0Item::STAGE_LABELS[$filters['stage']] ?? $filters['stage']) : '' }}
                    {{ $filters['posko_id'] !== '' ? ' · POSKO terpilih' : '' }}
                </span>
                <span>Manual P0 dibuat melalui detail sesi PASILA</span>
            </div>

            @if ($items->isEmpty())
                <div class="flex flex-col items-start justify-between gap-3 px-4 py-5 sm:flex-row sm:items-center">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Belum ada P0 untuk filter ini</h3>
                        <p class="mt-1 text-xs text-slate-600">P0 hanya dibuat dari temuan PASILA atau inspeksi tervalidasi yang sah. Tidak ada P0 yang dibuat tanpa sumber.</p>
                    </div>
                    <a href="{{ route('admin.pasila.index') }}"
                       class="shrink-0 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">
                        Lihat sesi PASILA
                    </a>
                </div>
            @else
                <x-admin.table min-width="min-w-[1100px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Kode P0</th>
                        <th class="px-4 py-3">Sesi PASILA</th>
                        <th class="px-4 py-3">Posko · Section</th>
                        <th class="px-4 py-3">Asal &amp; sumber</th>
                        <th class="px-4 py-3">Tahap</th>
                        <th class="px-4 py-3">WO P0</th>
                        <th class="px-4 py-3 text-center">Aksi</th>
                    </x-slot:head>

                    @foreach ($items as $item)
                        <tr class="hover:bg-blue-50/30" data-p0="{{ $item->code }}" data-stage="{{ $item->stage() }}">
                            <td class="px-4 py-3">
                                <div class="text-xs font-bold text-slate-900">{{ $item->code }}</div>
                                <div class="mt-1 text-[11px] text-slate-500">{{ $item->created_at->timezone($tz)->format('d/m/Y H:i') }} WIB</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <a href="{{ route('admin.pasila.show', [
                                    'posko' => $item->posko_id,
                                    'work_date' => $item->session->shiftInstance->operational_date->toDateString(),
                                    'shift_code' => $item->session->shiftInstance->shift_code,
                                ]) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->session->code }}</a>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-semibold text-slate-900">{{ $item->posko->nama_posko }}</div>
                                <div class="mt-1 text-slate-500">{{ $item->section_name }} ({{ $item->penyulang->kode_penyulang }})</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-semibold text-slate-700">{{ $item->originLabel() }}</div>
                                <div class="mt-1 text-slate-500">{{ $item->sourceLabel() }} · {{ $item->source_snapshot['finding_code'] ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <x-admin.status-badge :tone="$stageTone[$item->stage()]">{{ $item->stageLabel() }}</x-admin.status-badge>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($item->workOrder)
                                    <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->workOrder->code }}</a>
                                    <div class="mt-1 text-slate-500">{{ $item->workOrder->status->label() }}</div>
                                @else
                                    <span class="text-slate-500">Belum ada WO</span>
                                    <div class="mt-1 text-[11px] text-slate-400">Usulan belum menjadi pekerjaan</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('admin.p0.show', $item) }}"
                                   class="inline-flex items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-100">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        @endif
    </x-admin.panel>

    <details class="group rounded-xl border border-amber-200 bg-amber-50/60" data-p0-readiness>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-xs font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
            <span>Alur kerja, aturan P0, dan kesiapan usulan otomatis</span>
            <span class="shrink-0 text-amber-800 group-open:hidden">Buka +</span>
            <span class="hidden shrink-0 text-amber-800 group-open:inline">Tutup −</span>
        </summary>
        <div class="space-y-2 border-t border-amber-200 px-4 py-3 text-xs leading-5 text-slate-700">
            <p><strong>1. Sumber tercatat:</strong> temuan PASILA atau inspeksi tervalidasi pada Section milik POSKO yang sesuai.</p>
            <p><strong>2. Keputusan:</strong> usulan SYSTEM wajib ACC Admin sebelum menjadi WO. Jika ditolak, Admin wajib membuat P0 manual pengganti dari sesi yang sama.</p>
            <p><strong>3. Pelaksanaan:</strong> target minimal <strong>{{ \App\Services\Pasila\P0Service::TARGET_PER_SHIFT }} P0 selesai per POSKO per shift</strong>. P0 yang baru diusulkan atau dibuat belum otomatis memenuhi target; P0 terpisah dari kuota enam WO APKUK.</p>
            <p>Usulan SYSTEM otomatis: <strong>{{ $strategy->notReadyReason() ?? ($strategy->name().' '.$strategy->version()) }}</strong></p>
        </div>
    </details>
</div>
@endsection