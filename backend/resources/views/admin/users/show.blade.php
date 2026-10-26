@extends('layouts.admin')

@section('title', 'Akun '.($account->username ?? $account->name))

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        parent="User & Hak Akses"
        :parent-url="route('admin.users.index')"
        :title="$account->username ?? $account->name"
        state="live"
        description="Detail akun login. Password dan token tidak ditampilkan."
    >
        <x-slot:actions>
            <x-admin.unavailable-action label="Nonaktifkan" variant="secondary" reason="Perubahan status akun membutuhkan workflow beraudit yang belum tersedia." />
            <x-admin.unavailable-action label="Reset Password" variant="secondary" reason="Reset password Admin lain belum tersedia; Admin dapat mengganti password sendiri di Pengaturan Akun." />
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel title="Identitas akun">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Username">{{ $account->username }}</x-admin.detail-item>
            <x-admin.detail-item label="Nama">{{ $account->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Email">{{ $account->email }}</x-admin.detail-item>
            <x-admin.detail-item label="Role">{{ $account->role ?? 'Tanpa role' }}</x-admin.detail-item>
            <x-admin.detail-item label="Status">{{ $account->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="Akses Web Admin">{{ $account->canAccessAdmin() ? 'Diizinkan' : 'Ditolak' }}</x-admin.detail-item>
            <x-admin.detail-item label="Dibuat">{{ $fmt($account->created_at) }}</x-admin.detail-item>
            <x-admin.detail-item label="Diperbarui">{{ $fmt($account->updated_at) }}</x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <x-admin.panel flush title="Aktivitas tercatat" description="Hanya dari audit Work Order; audit domain lain belum tersedia.">
        @if ($load->failed('activity', 'logs'))
            <x-admin.error-state />
        @else
            <dl class="grid grid-cols-2 gap-3 p-4 text-center sm:max-w-md">
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase text-slate-500">WO dibuat</dt><dd class="text-2xl font-black text-slate-900">{{ $activity['work_orders_created'] }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase text-slate-500">Entri audit</dt><dd class="text-2xl font-black text-slate-900">{{ $activity['audit_entries'] }}</dd></div>
            </dl>
            <x-admin.table min-width="min-w-[640px]">
                <x-slot:head>
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">Aksi</th>
                    <th class="px-4 py-3">WO</th>
                    <th class="px-4 py-3 text-center">Detail</th>
                </x-slot:head>
                @forelse ($recentLogs as $log)
                    <tr>
                        <td class="px-4 py-3 text-xs">{{ $fmt($log->created_at) }}</td>
                        <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $log->action }}</td>
                        <td class="px-4 py-3">{{ $log->workOrder?->code ?? '-' }}</td>
                        <td class="px-4 py-3 text-center"><a href="{{ route('admin.audit-logs.show', $log) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-admin.empty-state title="Belum ada aktivitas tercatat." description="Akun ini belum tercatat sebagai pelaku di audit Work Order." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>
</div>
@endsection
