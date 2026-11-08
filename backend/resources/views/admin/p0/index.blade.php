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
<div class="mx-auto max-w-7xl space-y-5" data-p0-monitor-redesign="v2">
    <x-admin.posko-tabs />
    <x-admin.page-header
        section="Pekerjaan"
        title="P0 POSKO"
        state="partial"
        description="Monitor usulan, persetujuan, penugasan, dan penyelesaian P0 dari sumber masalah yang tercatat pada sesi PASILA."
    >
        <x-slot:actions>
            <a href="{{ route('admin.pasila.index') }}" class="inline-flex items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100">Buka PASILA</a>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.65fr)_minmax(280px,1fr)]">
        <section class="rounded-xl border border-blue-200 bg-blue-50/70 p-4 shadow-sm sm:p-5" aria-label="Ringkasan P0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Monitor P0</span>
                <span class="rounded-md border border-blue-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-blue-800">Pekerjaan POSKO</span>
            </div>
            <h2 class="mt-3 text-lg font-bold text-slate-900">Kendali pekerjaan P0 dari PASILA</h2>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">P0 harus memiliki sumber temuan yang sah dan Section milik POSKO. Usulan SYSTEM memerlukan ACC Admin sebelum menjadi pekerjaan; P0 manual dibuat dari detail sesi PASILA.</p>
            <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-600">
                <span class="rounded-lg border border-blue-100 bg-white px-3 py-2"><strong class="text-slate-900">{{ $load->failed('items') ? '—' : $countAll }}</strong> P0 tercatat dalam batas daftar{{ $filters['posko_id'] !== '' ? ' untuk POSKO terpilih' : '' }}</span>
                <span class="rounded-lg border border-amber-100 bg-white px-3 py-2"><strong class="text-amber-700">{{ $load->failed('items') ? '—' : $actionCount }}</strong> menunggu tindak lanjut Admin/verifikasi</span>
            </div>
        </section>
        <aside class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-label="Alur pekerjaan P0">
            <h2 class="text-sm font-bold text-slate-900">Alur singkat P0</h2>
            <ol class="mt-3 space-y-3 text-xs text-slate-600">
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 font-bold text-blue-700">1</span><span><strong class="text-slate-800">Sumber tercatat</strong><br>Temuan PASILA atau inspeksi tervalidasi pada Section yang sesuai.</span></li>
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 font-bold text-blue-700">2</span><span><strong class="text-slate-800">P0 disetujui / dibuat manual</strong><br>Usulan SYSTEM wajib ACC; P0 manual memakai sumber sah.</span></li>
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 font-bold text-blue-700">3</span><span><strong class="text-slate-800">Pekerjaan dan verifikasi</strong><br>Target shift dihitung hanya dari P0 yang selesai.</span></li>
            </ol>
        </aside>
    </div>

    <details class="group rounded-xl border border-amber-200 bg-amber-50/60" data-p0-readiness>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-xs font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
            <span>Aturan P0 dan kesiapan usulan otomatis</span>
            <span class="shrink-0 text-amber-800 group-open:hidden">Lihat detail +</span>
            <span class="hidden shrink-0 text-amber-800 group-open:inline">Tutup −</span>
        </summary>
        <div class="space-y-2 border-t border-amber-200 px-4 py-3 text-xs leading-5 text-slate-700">
            <p>Usulan SYSTEM wajib di-ACC Admin. Penolakan mewajibkan P0 manual pengganti pada sesi PASILA yang sama. Pengganti yang baru dibuat belum memenuhi target.</p>
            <p>Target minimal <strong>{{ \App\Services\Pasila\P0Service::TARGET_PER_SHIFT }} P0 selesai per POSKO per shift</strong>; status P0 terpisah dari kuota enam WO APKUK.</p>
            <p>Usulan SYSTEM otomatis: <strong>{{ $strategy->notReadyReason() ?? ($strategy->name().' '.$strategy->version()) }}</strong></p>
        </div>
    </details>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="Ringkasan tahap P0">
        @foreach ([P0Item::STAGE_MENUNGGU_ACC => 'amber', P0Item::STAGE_MENUNGGU_PENGGANTI => 'red', P0Item::STAGE_AKTIF => 'blue', P0Item::STAGE_MENUNGGU_VERIFIKASI => 'violet', P0Item::STAGE_SELESAI => 'emerald'] as $key => $tone)
            <x-admin.stat-card :label="P0Item::STAGE_LABELS[$key]" :value="$load->failed('items') ? null : ($counts[$key] ?? 0)" :tone="$tone" :href="route('admin.p0.index', ['stage' => $key, 'posko_id' => $filters['posko_id']])" unavailable-label="Gagal dimuat" />
        @endforeach
    </div>

    <x-admin.panel flush title="Daftar P0" :description="'Maksimal '.$limit.' P0 terbaru; pilih tahap atau POSKO untuk mempersempit daftar. Status P0 tidak sama dengan status WO APKUK.'">
        <x-admin.filter-bar :action="route('admin.p0.index')" :reset-url="route('admin.p0.index')" :active="request()->hasAny(['stage', 'posko_id'])">
            <x-admin.filter-select name="stage" label="Tahap" :options="$stageOptions" :value="$filters['stage']" placeholder="Semua tahap" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko" />
        </x-admin.filter-bar>
        @if ($load->failed('items'))
            <x-admin.error-state />
        @else
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-xs text-slate-600">
                <span><strong class="text-slate-900">{{ $items->count() }} P0 ditampilkan</strong>{{ $filters['stage'] !== '' ? ' · Tahap: '.(P0Item::STAGE_LABELS[$filters['stage']] ?? $filters['stage']) : '' }}{{ $filters['posko_id'] !== '' ? ' · POSKO terpilih' : '' }}</span>
                <span>Buat P0 manual melalui detail sesi PASILA</span>
            </div>
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
                @forelse ($items as $item)
                    <tr class="hover:bg-blue-50/30" data-p0="{{ $item->code }}" data-stage="{{ $item->stage() }}">
                        <td class="px-4 py-4">
                            <div class="text-xs font-bold text-slate-900">{{ $item->code }}</div>
                            <div class="mt-1 text-[11px] text-slate-500">{{ $item->created_at->timezone($tz)->format('d/m/Y H:i') }} WIB</div>
                        </td>
                        <td class="px-4 py-4 text-xs">
                            <a href="{{ route('admin.pasila.show', ['posko' => $item->posko_id, 'work_date' => $item->session->shiftInstance->operational_date->toDateString(), 'shift_code' => $item->session->shiftInstance->shift_code]) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->session->code }}</a>
                        </td>
                        <td class="px-4 py-4 text-xs">
                            <div class="font-semibold text-slate-900">{{ $item->posko->nama_posko }}</div>
                            <div class="mt-1 text-slate-500">{{ $item->section_name }} ({{ $item->penyulang->kode_penyulang }})</div>
                        </td>
                        <td class="px-4 py-4 text-xs">
                            <div class="font-semibold text-slate-700">{{ $item->originLabel() }}</div>
                            <div class="mt-1 text-slate-500">{{ $item->sourceLabel() }} · {{ $item->source_snapshot['finding_code'] ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-4"><x-admin.status-badge :tone="$stageTone[$item->stage()]">{{ $item->stageLabel() }}</x-admin.status-badge></td>
                        <td class="px-4 py-4 text-xs">
                            @if ($item->workOrder)
                                <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->workOrder->code }}</a>
                                <div class="mt-1 text-slate-500">{{ $item->workOrder->status->label() }}</div>
                            @else
                                <span class="text-slate-500">Belum ada WO</span>
                                <div class="mt-1 text-[11px] text-slate-400">Usulan belum menjadi pekerjaan</div>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-center">
                            <a href="{{ route('admin.p0.show', $item) }}" class="inline-flex items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-100">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-7">
                            <div class="mx-auto max-w-md py-5 text-center">
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-xl text-slate-400">—</div>
                                <h3 class="mt-3 text-sm font-bold text-slate-900">Belum ada P0 untuk filter ini</h3>
                                <p class="mt-2 text-xs leading-5 text-slate-600">P0 hanya dibuat dari sumber masalah yang tercatat dan sah pada sesi PASILA. Tidak ada P0 yang dibuat tanpa sumber.</p>
                                <a href="{{ route('admin.pasila.index') }}" class="mt-4 inline-flex rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-xs font-bold text-blue-700 hover:bg-blue-100">Lihat sesi PASILA</a>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>
</div>
@endsection
