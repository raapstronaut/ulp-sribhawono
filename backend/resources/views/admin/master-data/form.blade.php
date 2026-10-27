@extends('layouts.admin')

@section('title', ($model ? 'Ubah ' : 'Tambah ').$typeLabel)

@section('content')
@php
    $action = $model ? route('admin.master-data.manage.update', [$type, $model->getKey()]) : route('admin.master-data.manage.store', $type);
@endphp
<div class="mx-auto max-w-3xl space-y-6 pb-12">
    <x-admin.page-header section="Data & Admin" parent="Master Data" :parent-url="route('admin.master-data.index', ['tab' => $type])" :title="($model ? 'Ubah ' : 'Tambah ').\Illuminate\Support\Str::lower($typeLabel)"
        description="Perubahan tercatat di Audit Log beserta nilai sebelum dan sesudahnya." />

    <x-admin.panel>
        <form method="POST" action="{{ $action }}" class="space-y-4">
            @csrf
            @if ($model) @method('PUT') @endif

            @switch($type)
                @case('tim')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.input name="code" label="Kode tim" :value="$model?->code" required maxlength="50" hint="Huruf, angka, titik, garis bawah, atau tanda hubung." />
                        <x-admin.input name="name" label="Nama tim" :value="$model?->name" required maxlength="255" />
                        <x-admin.select name="role" label="Role" :options="$roleOptions" :value="$model?->role" placeholder="Pilih role" required :hint="$inUse ? 'Role tidak dapat diubah karena tim sudah dipakai.' : null" />
                        <x-admin.select name="posko_id" label="Posko" :options="$poskoOptions" :value="$model?->posko_id" placeholder="Tidak terikat Posko" hint="Wajib untuk tim POSKO." />
                    </div>
                    @break
                @case('petugas')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.select name="field_team_id" label="Tim" :options="$teamOptions" :value="$model?->field_team_id" placeholder="Pilih tim" required />
                        <x-admin.input name="personnel_code" label="Kode petugas" :value="$model?->personnel_code" maxlength="50" hint="Opsional, unik." />
                        <x-admin.input name="name" label="Nama petugas" :value="$model?->name" required maxlength="255" class="sm:col-span-2" />
                    </div>
                    @break
                @case('kode')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.select name="category" label="Kategori" :options="$categoryOptions" :value="$model?->category" placeholder="Pilih kategori" required />
                        <x-admin.input name="code" label="Kode" :value="$model?->code" required maxlength="80" hint="Huruf, angka, garis bawah. Disimpan kapital." />
                        <x-admin.input name="label" label="Label tampilan" :value="$model?->label" required maxlength="255" />
                        <x-admin.select name="role_scope" label="Berlaku untuk role" :options="$roleScopeOptions" :value="$model?->role_scope" placeholder="Semua role" />
                        <x-admin.input type="number" name="sort_order" label="Urutan" :value="$model?->sort_order ?? 0" min="0" max="9999" />
                    </div>
                    @break
                @case('jadwal')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.select name="field_team_id" label="Tim POSKO" :options="$poskoTeamOptions" :value="$model?->field_team_id" placeholder="Pilih tim" required />
                        <x-admin.select name="shift_code" label="Shift" :options="$shiftOptions" :value="$model?->shift_code" placeholder="Pilih shift" required />
                        <x-admin.input type="date" name="effective_from" label="Berlaku mulai" :value="$model?->effective_from?->toDateString()" required />
                        <x-admin.input type="date" name="effective_until" label="Berlaku sampai" :value="$model?->effective_until?->toDateString()" hint="Kosongkan bila tanpa batas." />
                    </div>
                    @break
            @endswitch

            @if ($type !== 'jadwal')
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $model?->is_active ?? true))> Aktif
                </label>
            @endif
            @if ($model)
                <x-admin.input name="reason" label="Alasan perubahan (opsional)" maxlength="1000" />
            @endif

            <div class="flex flex-wrap gap-3">
                <x-admin.button>Simpan</x-admin.button>
                <x-admin.button variant="secondary" :href="route('admin.master-data.index', ['tab' => $type])">Batal</x-admin.button>
            </div>
        </form>
    </x-admin.panel>

    @if ($model)
        <x-admin.panel title="Hapus data" :description="$inUse ? 'Data ini masih direferensikan transaksi sehingga tidak dapat dihapus. Nonaktifkan sebagai gantinya.' : 'Penghapusan permanen; tercatat di Audit Log.'">
            @if ($inUse)
                <x-admin.unavailable-action label="Hapus" variant="secondary" reason="Data masih direferensikan; nonaktifkan saja." />
            @else
                <form method="POST" action="{{ route('admin.master-data.manage.destroy', [$type, $model->getKey()]) }}" class="flex flex-col gap-3 sm:flex-row" data-confirm="Hapus data ini secara permanen?">
                    @csrf
                    @method('DELETE')
                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Alasan penghapusan (wajib)" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <x-admin.button variant="danger">Hapus</x-admin.button>
                </form>
            @endif
        </x-admin.panel>
    @endif
</div>
@endsection
