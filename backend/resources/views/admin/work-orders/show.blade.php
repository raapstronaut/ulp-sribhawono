@extends('layouts.admin')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $target = $wo->apkukTarget;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $statusClasses = [
        'DITUGASKAN' => 'bg-blue-50 text-blue-700 border-blue-200',
        'DIKERJAKAN' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'MENUNGGU_VERIFIKASI' => 'bg-amber-50 text-amber-700 border-amber-200',
        'RESCHEDULE_DIAJUKAN' => 'bg-amber-50 text-amber-700 border-amber-200',
        'DIJADWALKAN_ULANG' => 'bg-sky-50 text-sky-700 border-sky-200',
        'SELESAI' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'SELESAI_DITERUSKAN' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'DIBATALKAN' => 'bg-slate-100 text-slate-500 border-slate-200',
    ];
@endphp
<div class="max-w-6xl mx-auto space-y-6 pb-12">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Pekerjaan / Work Order</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-1">{{ $wo->code }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-bold {{ $statusClasses[$wo->status->value] ?? '' }}">{{ $wo->status->label() }}</span>
                @if($wo->isOverdue())
                    <span class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700">Terlambat</span>
                @endif
                <span class="text-xs text-slate-500">{{ $wo->team_type }} • {{ $wo->work_type }} • {{ $wo->source === \App\Models\WorkOrder::SOURCE_ADMIN_MANUAL ? 'Manual Admin' : $wo->source }}</span>
            </div>
        </div>
        <a href="{{ route('admin.work-orders.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Kembali ke WO</a>
    </div>

    @if(session('status') === 'work-order-created')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">WO APKUK berhasil dibuat.</div>
    @elseif(session('status') === 'work-order-cancelled')
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="status">WO dibatalkan. Tiang dapat dibuatkan WO baru.</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 lg:col-span-2">
            <h2 class="font-semibold text-slate-900 mb-4">Penugasan</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 text-sm">
                <div><div class="text-xs text-slate-500">POSKO penanggung jawab</div><div class="font-semibold text-slate-900">{{ $wo->posko?->nama_posko ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Penyulang</div><div class="font-semibold text-slate-900">{{ $wo->penyulang?->nama_penyulang ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Section</div><div class="font-semibold text-slate-900">{{ $wo->section_name ?? 'Belum diketahui' }}</div></div>
                <div><div class="text-xs text-slate-500">Tanggal kerja</div><div class="font-semibold text-slate-900">{{ $wo->work_date?->format('d/m/Y') }}</div></div>
                <div><div class="text-xs text-slate-500">Shift</div><div class="font-semibold text-slate-900">{{ $shift ? 'Shift '.$shift['code'].' — '.$shift['name'].' ('.$shift['time'].')' : '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Batas waktu</div><div class="font-semibold text-slate-900">{{ $fmt($wo->due_at) }}</div></div>
                <div><div class="text-xs text-slate-500">Dibuat oleh</div><div class="font-semibold text-slate-900">{{ $wo->createdBy?->username ?? $wo->createdBy?->name ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Dibuat</div><div class="font-semibold text-slate-900">{{ $fmt($wo->created_at) }}</div></div>
                <div><div class="text-xs text-slate-500">Diperbarui</div><div class="font-semibold text-slate-900">{{ $fmt($wo->updated_at) }}</div></div>
            </div>
            @if($wo->notes)
                <div class="mt-5 rounded-lg bg-slate-50 border border-slate-100 p-3 text-sm text-slate-700"><span class="text-xs text-slate-500 block mb-1">Catatan Admin</span>{{ $wo->notes }}</div>
            @endif
            @if($wo->cancelled_at)
                <div class="mt-5 rounded-lg bg-slate-50 border border-slate-200 p-3 text-sm text-slate-700">
                    <span class="text-xs text-slate-500 block mb-1">Dibatalkan {{ $fmt($wo->cancelled_at) }} oleh {{ $wo->cancelledBy?->username ?? '-' }}</span>
                    {{ $wo->cancel_reason }}
                </div>
            @endif
        </section>

        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="font-semibold text-slate-900 mb-4">Realisasi APKUK</h2>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] text-slate-500 uppercase font-bold">Target</div><div class="text-2xl font-black text-slate-900">{{ $target?->target_quantity ?? '-' }}</div></div>
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] text-slate-500 uppercase font-bold">Terpasang</div><div class="text-2xl font-black text-slate-900">{{ $target?->installed_quantity ?? '—' }}</div></div>
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] text-slate-500 uppercase font-bold">Sisa</div><div class="text-2xl font-black text-slate-900">{{ $target?->remaining_quantity ?? '—' }}</div></div>
            </div>
            <p class="mt-3 text-[11px] text-slate-500">Satuan buah (bh). Realisasi terisi dari laporan petugas POSKO.</p>
        </section>
    </div>

    @if($target)
    <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <h2 class="font-semibold text-slate-900">Target Tiang (snapshot data APKUK)</h2>
        <p class="text-xs text-slate-500 mt-1">Disalin saat WO dibuat dari baris apkuks #{{ $target->apkuk_id }}; sinkronisasi berikutnya tidak mengubah target WO.</p>
        <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-5 text-sm">
            <div><div class="text-xs text-slate-500">No. tiang</div><div class="font-semibold text-slate-900">{{ $target->no_tiang }}</div></div>
            <div><div class="text-xs text-slate-500">Penyulang (sumber)</div><div class="font-semibold text-slate-900">{{ $target->penyulang_source ?? '-' }}</div></div>
            <div><div class="text-xs text-slate-500">Section (sumber)</div><div class="font-semibold text-slate-900">{{ $target->section_source ?? '-' }}</div></div>
            <div><div class="text-xs text-slate-500">Naik dari</div><div class="font-semibold text-slate-900">{{ $target->naik_dari ?? '-' }}</div></div>
            <div><div class="text-xs text-slate-500">Tanggal laporan</div><div class="font-semibold text-slate-900">{{ $target->source_report_date?->format('d/m/Y') ?? '-' }}</div></div>
            <div><div class="text-xs text-slate-500">Status sumber</div><div class="font-semibold text-slate-900">{{ $target->source_status ?? '-' }}</div></div>
            <div><div class="text-xs text-slate-500">Terpasang / kurang (sumber)</div><div class="font-semibold text-slate-900">{{ $target->source_terpasang_bh ?? '-' }} / {{ $target->source_kurang_bh }}</div></div>
            <div>
                <div class="text-xs text-slate-500">Koordinat</div>
                @if($target->latitude && $target->longitude)
                    <a class="font-semibold text-blue-600 hover:text-blue-800" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $target->latitude }}&mlon={{ $target->longitude }}#map=18/{{ $target->latitude }}/{{ $target->longitude }}">{{ $target->latitude }}, {{ $target->longitude }}</a>
                @else
                    <div class="font-semibold text-slate-900">-</div>
                @endif
            </div>
        </div>
    </section>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="font-semibold text-slate-900">Laporan Pelaksanaan & Bukti</h2>
            @forelse($wo->reports as $report)
                <div class="mt-4 rounded-lg border border-slate-100 p-3 text-sm">
                    <div class="flex justify-between"><strong>{{ $report->report_type }}</strong><span class="text-xs text-slate-500">{{ $report->verification_status }}</span></div>
                    <div class="text-xs text-slate-500 mt-1">{{ $fmt($report->occurred_at) }} • Terpasang {{ $report->installed_quantity ?? '-' }} bh • Sisa {{ $report->remaining_quantity ?? '-' }} bh</div>
                    <div class="text-xs text-slate-600 mt-1">Petugas: {{ $report->personnel->pluck('personnel_name')->implode(', ') ?: '-' }}</div>
                    <div class="text-xs text-slate-600 mt-1">Foto: {{ $report->media->map(fn ($m) => $m->photo_type.' ('.$m->capture_source.')')->implode(', ') ?: '-' }}</div>
                </div>
            @empty
                <p class="text-sm text-slate-500 mt-2">Belum ada laporan dari POSKO. Laporan berisi foto BEFORE/AFTER (kamera/galeri), GPS, nama petugas, dan jumlah APKUK terpasang.</p>
            @endforelse
        </section>

        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="font-semibold text-slate-900">Reschedule</h2>
            @forelse($wo->reschedules as $reschedule)
                <div class="mt-4 rounded-lg border border-slate-100 p-3 text-sm">
                    <div class="flex justify-between"><strong>{{ $reschedule->reason_code }}</strong><span class="text-xs text-slate-500">{{ $reschedule->status }}</span></div>
                    <div class="text-xs text-slate-500 mt-1">{{ $reschedule->from_work_date?->format('d/m/Y') }} {{ $reschedule->from_shift_code }} → {{ $reschedule->proposed_work_date?->format('d/m/Y') }} {{ $reschedule->proposed_shift_code }}</div>
                    <div class="text-xs text-slate-600 mt-1">{{ $reschedule->note }}</div>
                </div>
            @empty
                <p class="text-sm text-slate-500 mt-2">Belum ada pengajuan reschedule.</p>
            @endforelse
        </section>
    </div>

    <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <h2 class="font-semibold text-slate-900">Riwayat Audit</h2>
        <ol class="mt-4 space-y-3">
            @foreach($wo->statusLogs as $log)
                <li class="flex gap-3 text-sm">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                    <div>
                        <div class="font-semibold text-slate-800">
                            {{ $log->action }}
                            @if($log->from_status || $log->to_status)
                                <span class="font-normal text-slate-500">{{ $log->from_status ?? '—' }} → {{ $log->to_status ?? '—' }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-500">{{ $fmt($log->created_at) }} • {{ $log->actor?->username ?? $log->actor?->name ?? 'Sistem' }}</div>
                        @if($log->reason)
                            <div class="text-xs text-slate-600 mt-1">Alasan: {{ $log->reason }}</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    @if($wo->status->canTransitionTo(\App\Enums\WorkOrderStatus::Dibatalkan))
        <section class="bg-white rounded-xl border border-red-100 shadow-sm p-6">
            <h2 class="font-semibold text-slate-900">Batalkan WO</h2>
            <p class="text-xs text-slate-500 mt-1">Pembatalan tercatat di riwayat audit dan melepas kunci tiang sehingga dapat dibuatkan WO baru.</p>
            <form method="POST" action="{{ route('admin.work-orders.cancel', $wo) }}" class="mt-4 flex flex-col md:flex-row gap-3">
                @csrf
                <input type="text" name="cancel_reason" required minlength="5" maxlength="1000" value="{{ old('cancel_reason') }}" placeholder="Alasan pembatalan (wajib)" class="flex-1 rounded-lg border px-3 py-2 text-sm {{ $errors->has('cancel_reason') ? 'border-red-400' : 'border-slate-300' }}">
                <button type="submit" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Batalkan WO</button>
            </form>
        </section>
    @endif
</div>
@endsection
