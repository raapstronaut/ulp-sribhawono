@extends('layouts.admin')

@section('title', 'Audit Log — Umum')

@section('content')
@php $tz = \App\Services\ShiftService::TIMEZONE; @endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Data & Admin" title="Audit Log & Histori" state="live"
        description="Perubahan master data, akun & hak akses, inspeksi, serta keputusan limpahan/temuan/kendala. Append-only; kata sandi tidak pernah dicatat." />

    @include('admin.audit-logs.partials.tabs', ['active' => 'general'])

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.audit-logs.general')" :reset-url="route('admin.audit-logs.general')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Cari objek" :value="$filters['q']" placeholder="Nama/kode objek" />
            <x-admin.filter-select name="entity_type" label="Jenis objek" :options="\App\Models\AuditLog::ENTITY_LABELS" :value="$filters['entity_type']" placeholder="Semua objek" />
            <x-admin.filter-select name="action" label="Tindakan" :options="\App\Models\AuditLog::ACTION_LABELS" :value="$filters['action']" placeholder="Semua tindakan" />
            <x-admin.filter-input type="date" name="date_from" label="Dari" :value="$filters['date_from']" />
            <x-admin.filter-input type="date" name="date_to" label="Sampai" :value="$filters['date_to']" />
        </x-admin.filter-bar>
        @if ($load->failed('logs'))
            <x-admin.error-state />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Objek</th><th class="px-4 py-3">Tindakan</th><th class="px-4 py-3">Pelaku</th><th class="px-4 py-3">Alasan</th><th class="px-4 py-3"></th>
                </x-slot:head>
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-xs">{{ $log->created_at->copy()->timezone($tz)->format('d/m/Y H:i:s') }} WIB</td>
                        <td class="px-4 py-3 text-xs"><span class="font-semibold">{{ $log->entityTypeLabel() }}</span><div class="text-slate-500">{{ $log->entity_label ?? $log->entity_id }}</div></td>
                        <td class="px-4 py-3 text-xs font-semibold">{{ $log->actionLabel() }}</td>
                        <td class="px-4 py-3 text-xs">{{ $log->actor?->displayName() ?? $log->actorFieldTeam?->name ?? 'Sistem' }}</td>
                        <td class="px-4 py-3 text-xs">{{ \Illuminate\Support\Str::limit($log->reason, 60) ?: '-' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.audit-logs.general.show', $log) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-admin.empty-state title="Belum ada entri audit umum." description="Entri muncul setelah ada perubahan master data, akun, atau keputusan Admin." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$logs" label="entri" />
        @endif
    </x-admin.panel>
</div>
@endsection
