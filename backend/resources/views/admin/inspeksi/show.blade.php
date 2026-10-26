@extends('layouts.admin')

@section('title', 'Detail Inspeksi')

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        parent="Inspeksi"
        :parent-url="route('admin.inspeksi.index')"
        title="Detail Inspeksi"
        state="skeleton"
    />

    <x-admin.panel>
        <x-admin.empty-state
            title="Data inspeksi tidak ditemukan."
            description="Kunjungan atau temuan dengan ID tersebut tidak ada. Domain inspeksi belum dibangun sehingga belum ada data yang dapat ditampilkan."
        >
            <a href="{{ route('admin.inspeksi.index') }}" class="mt-4 text-sm font-semibold text-blue-600 hover:text-blue-800">Kembali ke Inspeksi</a>
        </x-admin.empty-state>
        <dl class="mt-2 grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3">
            <x-admin.detail-item label="ID diminta">{{ $inspectionId }}</x-admin.detail-item>
            <x-admin.detail-item label="Foto & GPS temuan">Belum tersedia</x-admin.detail-item>
            <x-admin.detail-item label="Status validasi">Belum tersedia</x-admin.detail-item>
        </dl>
    </x-admin.panel>
</div>
@endsection
