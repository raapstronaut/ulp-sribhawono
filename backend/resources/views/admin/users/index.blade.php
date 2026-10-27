@extends('layouts.admin')

@section('title', 'User & Hak Akses')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        title="User & Hak Akses"
        state="live"
        description="Akun login dan pembagian hak akses lima role. Web Admin hanya untuk ADMIN; empat akun tim lapangan memakai aplikasi Android lewat API mobile."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.users.create')">Tambah akun</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice title="Pengaman akses:">
        Akun lapangan wajib terikat tim aktif dengan role sama. Admin tidak dapat menonaktifkan atau menurunkan role akunnya sendiri, dan minimal satu ADMIN aktif harus tersisa. Menonaktifkan akun atau mereset kata sandi mencabut sesi dan token API. Batas dua perangkat dan logout berkala empat jam masih TBD dan belum diterapkan.
    </x-admin.notice>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.stat-card label="Total akun login" :value="$summary['total']" hint="Tabel users" />
            <x-admin.stat-card label="ADMIN aktif" :value="$summary['active_admins']" hint="Dapat membuka Web Admin" tone="emerald" />
            <x-admin.stat-card label="Akun nonaktif" :value="$summary['inactive']" hint="Ditolak oleh middleware admin" />
            <x-admin.stat-card label="Tim lapangan" :value="$summary['field_teams']" hint="Master tim; akun lapangan terikat ke salah satu tim" :href="route('admin.master-data.index', ['tab' => 'tim'])" />
        </div>
    @endif

    <x-admin.panel flush title="Daftar akun" description="Password dan token tidak pernah ditampilkan.">
        <x-admin.filter-bar :action="route('admin.users.index')" :reset-url="route('admin.users.index')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" placeholder="Username, nama, atau email" />
            <x-admin.filter-select name="role" label="Role" :options="\App\Http\Controllers\Admin\UserAccessController::ROLES" :value="$filters['role']" placeholder="Semua role" />
            <x-admin.filter-select name="status" label="Status" :options="\App\Http\Controllers\Admin\UserAccessController::STATUSES" :value="$filters['status']" placeholder="Semua status" />
        </x-admin.filter-bar>

        @if ($load->failed('users'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[860px]">
                <x-slot:head>
                    <th class="px-4 py-3">Username</th>
                    <th class="px-4 py-3">Nama / Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Dibuat</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($users as $account)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $account->username ?? '-' }}</td>
                        <td class="px-4 py-3"><div class="text-slate-800">{{ $account->name }}</div><div class="text-[10px] text-slate-500">{{ $account->email }}</div></td>
                        <td class="px-4 py-3">@if ($account->role)<x-admin.status-badge :tone="$account->role === \App\Models\User::ROLE_ADMIN ? 'blue' : 'violet'">{{ $account->role }}</x-admin.status-badge>@else<span class="text-xs text-slate-400">Tanpa role</span>@endif @if ($account->fieldTeam)<div class="mt-0.5 text-[10px] text-slate-500">{{ $account->fieldTeam->name }}</div>@endif</td>
                        <td class="px-4 py-3"><x-admin.status-badge :tone="$account->is_active ? 'emerald' : 'slate'">{{ $account->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                        <td class="px-4 py-3 text-xs">{{ $account->created_at?->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
                        <td class="px-4 py-3 text-center"><a href="{{ route('admin.users.show', $account) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-admin.empty-state title="Tidak ada akun." description="Tidak ada akun yang cocok dengan filter." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$users" label="akun" />
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="Matriks hak akses" description="Lima role terkunci menurut dokumen v3. Akun login mewakili tim; petugas aktual dipilih dari roster saat melapor.">
        <x-admin.table min-width="min-w-[860px]">
            <x-slot:head>
                <th class="px-4 py-3">Role</th>
                <th class="px-4 py-3">Platform</th>
                <th class="px-4 py-3">Cakupan pekerjaan</th>
                <th class="px-4 py-3">Penerapan saat ini</th>
            </x-slot:head>
            @foreach (\App\Http\Controllers\Admin\UserAccessController::ROLE_MATRIX as $entry)
                <tr class="align-top">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $entry['role'] }}</td>
                    <td class="px-4 py-3 text-xs">{{ $entry['platform'] }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $entry['scope'] }}</td>
                    <td class="px-4 py-3"><x-admin.status-badge :tone="$entry['role'] === 'ADMIN' ? 'emerald' : 'amber'">{{ $entry['status'] }}</x-admin.status-badge></td>
                </tr>
            @endforeach
        </x-admin.table>
    </x-admin.panel>
</div>
@endsection
