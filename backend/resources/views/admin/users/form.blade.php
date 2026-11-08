@extends('layouts.admin')

@section('title', $account ? 'Ubah akun' : 'Tambah akun')

@section('content')
<div class="mx-auto max-w-3xl space-y-6 pb-12">
    <x-admin.page-header section="Data & Admin" parent="User & Hak Akses" :parent-url="route('admin.users.index')" :title="$account ? 'Ubah akun '.$account->displayName() : 'Tambah akun'"
        description="ADMIN mengakses Web Admin. Akun POSKO/HARSUS/INSPEKSI/ROW hanya untuk aplikasi Android dan wajib terikat ke tim dengan role yang sama." />

    <x-admin.panel>
        <form method="POST" action="{{ $account ? route('admin.users.update', $account) : route('admin.users.store') }}" class="space-y-4">
            @csrf
            @if ($account) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.input name="name" label="Nama" :value="$account?->name" required maxlength="255" />
                <x-admin.input name="username" label="Username" :value="$account?->username" required maxlength="50" hint="Huruf, angka, tanda hubung, garis bawah." autocomplete="off" />
                <x-admin.input type="email" name="email" label="Email" :value="$account?->email" required maxlength="255" />
                <x-admin.select name="role" label="Role" :options="\App\Models\User::ROLE_LABELS" :value="$account?->role" placeholder="Pilih role" required />
                <label class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-semibold text-slate-700">Tim (wajib untuk akun lapangan)</span>
                    <select name="field_team_id" class="w-full rounded-lg border {{ $errors->has('field_team_id') ? 'border-red-400' : 'border-slate-300' }} bg-white px-3 py-2 text-sm">
                        <option value="">Tidak terikat tim (ADMIN)</option>
                        @foreach ($teamOptions as $role => $teams)
                            <optgroup label="{{ $role }}">
                                @foreach ($teams as $id => $name)
                                    <option value="{{ $id }}" @selected(old('field_team_id', $account?->field_team_id) === $id)>{{ $name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('field_team_id')<span class="text-[11px] text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-semibold text-slate-700">Identitas petugas pribadi (INSPEKSI / HARSUS)</span>
                    <select name="field_personnel_id" class="w-full rounded-lg border {{ $errors->has('field_personnel_id') ? 'border-red-400' : 'border-slate-300' }} bg-white px-3 py-2 text-sm">
                        <option value="">Tidak ditautkan (POSKO / ADMIN / akun lama)</option>
                        @foreach ($personnelOptions as $group => $persons)
                            <optgroup label="{{ $group }}">
                                @foreach ($persons as $id => $name)
                                    <option value="{{ $id }}" @selected(old('field_personnel_id', $account?->field_personnel_id) === $id)>{{ $name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <span class="text-[11px] text-slate-500">Pilih petugas dari tim yang sama dengan akun. Identitas ini tidak digunakan untuk login POSKO bersama.</span>
                    @error('field_personnel_id')<span class="text-[11px] text-red-600">{{ $message }}</span>@enderror
                </label>
            </div>
            @if (! $account)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.input type="password" name="password" label="Kata sandi" required autocomplete="new-password" hint="Minimal 8 karakter, berisi huruf dan angka." />
                    <x-admin.input type="password" name="password_confirmation" label="Ulangi kata sandi" required autocomplete="new-password" />
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Aktif</label>
            @else
                <x-admin.input name="reason" label="Alasan perubahan (opsional)" maxlength="1000" hint="Perubahan role/tim mencabut token API akun ini." />
            @endif
            <div class="flex flex-wrap gap-3">
                <x-admin.button>{{ $account ? 'Simpan perubahan' : 'Buat akun' }}</x-admin.button>
                <x-admin.button variant="secondary" :href="$account ? route('admin.users.show', $account) : route('admin.users.index')">Batal</x-admin.button>
            </div>
        </form>
    </x-admin.panel>
</div>
@endsection
