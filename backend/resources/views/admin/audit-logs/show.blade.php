@extends('layouts.admin')

@section('title', 'Entri Audit #'.$log->id)

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
@endphp
<div class="mx-auto max-w-5xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        parent="Audit Log"
        :parent-url="route('admin.audit-logs.index')"
        :title="'Entri Audit #'.$log->id"
        state="live"
        description="Entri append-only; tidak dapat diubah atau dihapus."
    />

    <x-admin.panel title="Ringkasan">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-3">
            <x-admin.detail-item label="Aksi">{{ $actionLabel }} <span class="font-mono text-[10px] font-normal text-slate-500">{{ $log->action }}</span></x-admin.detail-item>
            <x-admin.detail-item label="Waktu tercatat">{{ $log->created_at?->timezone($tz)->format('d/m/Y H:i:s') }} WIB</x-admin.detail-item>
            <x-admin.detail-item label="Perubahan status">{{ $log->from_status ?? '—' }} → {{ $log->to_status ?? '—' }}</x-admin.detail-item>
            <x-admin.detail-item label="Pelaku (Admin)">{{ $log->actor?->username ?? $log->actor?->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Pelaku (tim lapangan)">{{ $log->actorFieldTeam?->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Work Order">
                @if ($log->workOrder)
                    <a href="{{ route('admin.work-orders.show', $log->workOrder) }}" class="text-blue-600 hover:text-blue-800">{{ $log->workOrder->code }}</a>
                    <span class="block text-[10px] font-normal text-slate-500">{{ $log->workOrder->team_type }} · {{ $log->workOrder->work_type }}</span>
                @endif
            </x-admin.detail-item>
        </dl>
        <div class="mt-5 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700">
            <span class="mb-1 block text-xs text-slate-500">Alasan</span>
            {{ $log->reason ?: 'Tidak ada alasan tercatat.' }}
        </div>
    </x-admin.panel>

    <x-admin.panel title="Payload" description="Data tambahan yang disimpan bersama entri (JSON).">
        @if ($payloadJson)
            <pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-[11px] leading-relaxed text-slate-100">{{ $payloadJson }}</pre>
        @else
            <p class="text-xs text-slate-500">Tidak ada payload pada entri ini.</p>
        @endif
    </x-admin.panel>
</div>
@endsection
