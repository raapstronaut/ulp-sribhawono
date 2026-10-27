@extends('layouts.admin')

@section('title', 'Entri audit #'.$log->id)

@section('content')
@php $tz = \App\Services\ShiftService::TIMEZONE; @endphp
<div class="mx-auto max-w-5xl space-y-6 pb-12">
    <x-admin.page-header section="Data & Admin" parent="Audit Log" :parent-url="route('admin.audit-logs.general')" :title="'Entri audit #'.$log->id" :description="$log->entityTypeLabel().' · '.$log->actionLabel()" />

    <x-admin.panel title="Ringkasan">
        <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-3">
            <x-admin.detail-item label="Waktu">{{ $log->created_at->copy()->timezone($tz)->format('d/m/Y H:i:s') }} WIB</x-admin.detail-item>
            <x-admin.detail-item label="Pelaku">{{ $log->actor?->displayName() ?? $log->actorFieldTeam?->name ?? 'Sistem' }}</x-admin.detail-item>
            <x-admin.detail-item label="Alamat IP">{{ $log->ip_address }}</x-admin.detail-item>
            <x-admin.detail-item label="Jenis objek">{{ $log->entityTypeLabel() }}</x-admin.detail-item>
            <x-admin.detail-item label="Objek">{{ $log->entity_label }} <span class="text-xs font-normal text-slate-500">({{ $log->entity_id }})</span></x-admin.detail-item>
            <x-admin.detail-item label="Tindakan">{{ $log->actionLabel() }}</x-admin.detail-item>
        </dl>
        @if ($log->reason)
            <p class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700"><span class="mb-1 block text-xs text-slate-500">Alasan</span>{{ $log->reason }}</p>
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-admin.panel title="Sebelum">
            @if ($beforeJson)<pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-[11px] text-slate-100">{{ $beforeJson }}</pre>@else<p class="text-xs text-slate-500">Tidak ada (objek baru atau tidak relevan).</p>@endif
        </x-admin.panel>
        <x-admin.panel title="Sesudah">
            @if ($afterJson)<pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-[11px] text-slate-100">{{ $afterJson }}</pre>@else<p class="text-xs text-slate-500">Tidak ada (objek dihapus atau tidak relevan).</p>@endif
        </x-admin.panel>
    </div>
</div>
@endsection
