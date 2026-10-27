@extends('layouts.admin')

@section('title', 'Audit Log')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        title="Audit Log & Histori"
        state="live"
        description="Jejak append-only: pelaku, waktu, tindakan, objek, nilai sebelum/sesudah, dan alasan. Tidak dapat diubah atau dihapus dari aplikasi."
    />

    @include('admin.audit-logs.partials.tabs', ['active' => 'wo'])

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-admin.stat-card label="Total entri audit" :value="$summary['total']" hint="work_order_status_logs" />
            <x-admin.stat-card label="Entri hari ini" :value="$summary['today']" hint="Sejak 00:00 WIB" />
            <x-admin.stat-card label="Pembatalan WO" :value="$summary['cancelled']" hint="Aksi CANCELLED dengan alasan" />
        </div>
    @endif

    <x-admin.panel flush title="Entri audit" description="Diurutkan dari yang terbaru.">
        <x-admin.filter-bar :action="route('admin.audit-logs.index')" :reset-url="route('admin.audit-logs.index')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Kode WO" :value="$filters['q']" placeholder="Contoh: WO-APKUK" />
            <x-admin.filter-select name="action" label="Aksi" :options="$actionOptions" :value="$filters['action']" placeholder="Semua aksi" />
            <x-admin.filter-select name="team_type" label="Tim" :options="\App\Http\Controllers\Admin\AuditLogController::TEAM_TYPES" :value="$filters['team_type']" placeholder="Semua tim" />
            <x-admin.filter-input type="date" name="date_from" label="Dari tanggal" :value="$filters['date_from']" />
            <x-admin.filter-input type="date" name="date_to" label="Sampai tanggal" :value="$filters['date_to']" />
        </x-admin.filter-bar>

        @if ($load->failed('logs'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[960px]">
                <x-slot:head>
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">Aksi</th>
                    <th class="px-4 py-3">WO</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Pelaku</th>
                    <th class="px-4 py-3">Alasan</th>
                    <th class="px-4 py-3 text-center">Detail</th>
                </x-slot:head>
                @forelse ($logs as $log)
                    <tr class="align-top hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-4 py-3 text-xs">{{ $log->created_at?->timezone($tz)->format('d/m/Y H:i:s') }} WIB</td>
                        <td class="px-4 py-3"><x-admin.status-badge :tone="$log->action === \App\Models\WorkOrderStatusLog::ACTION_CANCELLED ? 'red' : 'blue'">{{ \App\Http\Controllers\Admin\AuditLogController::ACTIONS[$log->action] ?? $log->action }}</x-admin.status-badge></td>
                        <td class="px-4 py-3">
                            @if ($log->workOrder)
                                <a href="{{ route('admin.work-orders.show', $log->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $log->workOrder->code }}</a>
                                <div class="text-[10px] text-slate-500">{{ $log->workOrder->team_type }} · {{ $log->workOrder->work_type }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $log->from_status ?? '—' }} → {{ $log->to_status ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $log->actor?->username ?? $log->actor?->name ?? $log->actorFieldTeam?->name ?? 'Sistem' }}</td>
                        <td class="max-w-xs px-4 py-3 text-[11px] text-slate-600">{{ \Illuminate\Support\Str::limit((string) $log->reason, 120) ?: '-' }}</td>
                        <td class="px-4 py-3 text-center"><a href="{{ route('admin.audit-logs.show', $log) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-admin.empty-state title="Belum ada entri audit." description="Belum ada perubahan Work Order yang tercatat atau tidak ada yang cocok dengan filter." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$logs" label="entri audit" />
        @endif
    </x-admin.panel>
</div>
@endsection
