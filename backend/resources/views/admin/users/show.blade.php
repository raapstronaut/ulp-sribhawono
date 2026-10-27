@extends('layouts.admin')

@section('title', 'Akun '.($account->username ?? $account->name))

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $isSelf = $account->is(auth()->user());
@endphp
<div class="mx-auto max-w-6xl space-y-6 pb-12">
    <x-admin.page-header section="Data & Admin" parent="User & Hak Akses" :parent-url="route('admin.users.index')" :title="$account->username ?? $account->name" state="live"
        description="Detail akun login. Password dan token tidak ditampilkan.">
        <x-slot:actions>
            <x-admin.button variant="secondary" size="sm" :href="route('admin.users.edit', $account)">Ubah akun</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel title="Identitas akun">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Username">{{ $account->username }}</x-admin.detail-item>
            <x-admin.detail-item label="Nama">{{ $account->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Email">{{ $account->email }}</x-admin.detail-item>
            <x-admin.detail-item label="Role">{{ \App\Models\User::ROLE_LABELS[$account->role] ?? ($account->role ?? 'Tanpa role') }}</x-admin.detail-item>
            <x-admin.detail-item label="Tim">{{ $account->fieldTeam?->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Status">{{ $account->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="Akses Web Admin">{{ $account->canAccessAdmin() ? 'Diizinkan' : 'Ditolak' }}</x-admin.detail-item>
            <x-admin.detail-item label="Akses aplikasi lapangan">{{ $account->canUseFieldApi() ? 'Diizinkan' : 'Ditolak' }}</x-admin.detail-item>
            <x-admin.detail-item label="Token API aktif">{{ $tokenCount }}</x-admin.detail-item>
            <x-admin.detail-item label="Dibuat">{{ $fmt($account->created_at) }}</x-admin.detail-item>
            <x-admin.detail-item label="Diperbarui">{{ $fmt($account->updated_at) }}</x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-admin.panel title="{{ $account->is_active ? 'Nonaktifkan akun' : 'Aktifkan akun' }}" :description="$account->is_active ? 'Akun nonaktif tidak dapat login; sesi dan token API dicabut.' : 'Akun lapangan hanya dapat diaktifkan bila timnya aktif.'">
            @if ($isSelf && $account->is_active)
                <x-admin.unavailable-action label="Nonaktifkan" variant="secondary" reason="Anda tidak dapat menonaktifkan akun Anda sendiri." />
            @else
                <form method="POST" action="{{ route('admin.users.activation', $account) }}" class="flex flex-col gap-3 sm:flex-row" data-confirm="{{ $account->is_active ? 'Nonaktifkan akun ini?' : 'Aktifkan akun ini?' }}">
                    @csrf
                    <input type="hidden" name="active" value="{{ $account->is_active ? 0 : 1 }}">
                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Alasan (wajib)" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <x-admin.button :variant="$account->is_active ? 'danger' : 'success'">{{ $account->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</x-admin.button>
                </form>
            @endif
        </x-admin.panel>
        <x-admin.panel title="Reset kata sandi" description="Kata sandi tidak dicatat; hanya fakta reset beserta alasan yang masuk Audit Log.">
            <form method="POST" action="{{ route('admin.users.password', $account) }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-confirm="Reset kata sandi akun ini?">
                @csrf
                <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Kata sandi baru" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" placeholder="Ulangi kata sandi" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Alasan (wajib)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <x-admin.button variant="secondary" class="sm:col-span-2">Reset kata sandi</x-admin.button>
            </form>
        </x-admin.panel>
    </div>

    <x-admin.panel flush title="Audit akun & tindakan" description="Perubahan pada akun ini dan tindakan akun ini di luar WO.">
        @if ($load->failed('account_audit'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[640px]">
                <x-slot:head><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Objek</th><th class="px-4 py-3">Tindakan</th><th class="px-4 py-3">Pelaku</th><th class="px-4 py-3"></th></x-slot:head>
                @forelse ($accountAudit as $entry)
                    <tr>
                        <td class="px-4 py-3 text-xs">{{ $fmt($entry->created_at) }}</td>
                        <td class="px-4 py-3 text-xs">{{ $entry->entityTypeLabel() }} · {{ $entry->entity_label }}</td>
                        <td class="px-4 py-3 text-xs font-semibold">{{ $entry->actionLabel() }}</td>
                        <td class="px-4 py-3 text-xs">{{ $entry->actor?->displayName() ?? 'Sistem' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.audit-logs.general.show', $entry) }}" class="text-xs font-semibold text-blue-600">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-admin.empty-state title="Belum ada entri audit umum." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="Aktivitas Work Order" description="Dari audit Work Order (append-only).">
        @if ($load->failed('activity', 'logs'))
            <x-admin.error-state />
        @else
            <dl class="grid grid-cols-2 gap-3 p-4 text-center sm:max-w-md">
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase text-slate-500">WO dibuat</dt><dd class="text-2xl font-black text-slate-900">{{ $activity['work_orders_created'] }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase text-slate-500">Entri audit WO</dt><dd class="text-2xl font-black text-slate-900">{{ $activity['audit_entries'] }}</dd></div>
            </dl>
            <x-admin.table min-width="min-w-[640px]">
                <x-slot:head><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Aksi</th><th class="px-4 py-3">WO</th><th class="px-4 py-3 text-center">Detail</th></x-slot:head>
                @forelse ($recentLogs as $log)
                    <tr>
                        <td class="px-4 py-3 text-xs">{{ $fmt($log->created_at) }}</td>
                        <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ \App\Models\WorkOrderStatusLog::ACTION_LABELS[$log->action] ?? $log->action }}</td>
                        <td class="px-4 py-3">{{ $log->workOrder?->code ?? '-' }}</td>
                        <td class="px-4 py-3 text-center"><a href="{{ route('admin.audit-logs.show', $log) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-admin.empty-state title="Belum ada aktivitas WO." description="Akun ini belum tercatat sebagai pelaku di audit Work Order." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>
</div>
@endsection
