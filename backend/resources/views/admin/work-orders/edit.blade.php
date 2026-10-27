@extends('layouts.admin')

@section('title', 'Ubah '.$wo->code)

@section('content')
<div class="mx-auto max-w-3xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" parent="{{ $wo->code }}" :parent-url="route('admin.work-orders.show', $wo)" title="Ubah data / jadwal WO"
        description="Status WO tidak berubah. Alasan wajib diisi; nilai sebelum dan sesudah tercatat di riwayat audit." />

    <x-admin.panel>
        <form method="POST" action="{{ route('admin.work-orders.update', $wo) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.input type="date" name="work_date" label="Tanggal kerja" :value="$wo->work_date?->toDateString()" required />
                @if ($wo->shift_code !== null)
                    <x-admin.select name="shift_code" label="Shift" :options="$shiftOptions" :value="$wo->shift_code" required hint="Batas waktu mengikuti akhir shift." />
                @else
                    <x-admin.input type="date" name="due_date" label="Target selesai" :value="$dueDate" required hint="Batas waktu pukul 23:59 WIB." />
                @endif
                <x-admin.select name="field_team_id" label="Tim pelaksana" :options="$teamOptions" :value="$wo->field_team_id" :placeholder="'Semua tim '.$wo->teamLabel()" />
                <x-admin.input name="section_name" label="Section" :value="$wo->section_name" maxlength="255" />
            </div>
            <x-admin.textarea name="notes" label="Catatan" :value="$wo->notes" rows="3" />
            <x-admin.textarea name="reason" label="Alasan perubahan" rows="2" required hint="Contoh: koreksi tim pelaksana sesuai jadwal shift." />
            <div class="flex flex-wrap items-center gap-3">
                <x-admin.button>Simpan perubahan</x-admin.button>
                <x-admin.button variant="secondary" :href="route('admin.work-orders.show', $wo)">Batal</x-admin.button>
            </div>
        </form>
    </x-admin.panel>
</div>
@endsection
