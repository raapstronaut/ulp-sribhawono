@extends('layouts.admin')

@section('title', 'HARSUS')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="HARSUS"
        state="live"
        description="WO PEKERJAAN_KHUSUS dari Admin (wajib menunjuk aset jaringan) atau limpahan lintas tim yang telah di-ACC Admin."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.team-work-orders.create', 'harsus')">Buat Pekerjaan Khusus</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice title="Aturan HARSUS:">
        HARSUS bukan pelaksana P0; P0 tetap pekerjaan POSKO dalam PASILA. Cuaca buruk atau akses terhalang bukan alasan otomatis melimpahkan ke HARSUS; gunakan pending/reschedule. Limpahan dibuat otomatis sebagai child WO setelah permintaan eskalasi di-ACC Admin di Review & Validasi. Aset target WO manual memakai data keypoint hasil sinkronisasi karena master aset canonical belum ada.
    </x-admin.notice>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.stat-card label="WO Khusus Aktif" :value="$summary['active']" hint="Belum selesai, diteruskan, atau dibatalkan" />
            <x-admin.stat-card label="Dari Admin" :value="$summary['by_source'][\App\Models\WorkOrder::SOURCE_ADMIN_MANUAL] ?? 0" hint="WO manual Admin yang masih aktif" />
            <x-admin.stat-card label="Limpahan" :value="$summary['by_source'][\App\Models\WorkOrder::SOURCE_ESCALATION] ?? 0" hint="Child WO hasil eskalasi yang masih aktif" />
            <x-admin.stat-card label="Selesai hari ini" :value="$summary['completed_today']" hint="Status SELESAI pada tanggal WIB berjalan" tone="emerald" />
        </div>
    @endif

    <x-admin.panel flush title="Daftar WO Pekerjaan Khusus" description="Kolom Asal menautkan WO sumber bila pekerjaan berasal dari eskalasi.">
        <x-admin.filter-bar :action="route('admin.harsus.index')" :reset-url="route('admin.harsus.index')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" placeholder="Kode WO atau Section" />
            <x-admin.filter-select name="source" label="Sumber" :options="$sourceOptions" :value="$filters['source']" placeholder="Semua sumber" />
            <x-admin.filter-select name="status" label="Status" :options="$statusOptions" :value="$filters['status']" placeholder="Semua status" />
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$filters['penyulang_id']" placeholder="Semua penyulang" />
            <x-admin.filter-input type="date" name="work_date" label="Tanggal kerja" :value="$filters['work_date']" />
        </x-admin.filter-bar>

        @if ($load->failed('work_orders'))
            <x-admin.error-state />
        @else
            <x-admin.work-order-table
                :work-orders="$workOrders"
                empty-title="Belum ada WO Pekerjaan Khusus."
                empty-description="Belum ada WO HARSUS tersimpan atau tidak ada data yang cocok dengan filter."
            />
            <x-admin.pagination :paginator="$workOrders" label="WO HARSUS" />
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Aset Target & Kebutuhan Teknis"
            description="Setiap WO khusus akan menampilkan aset jaringan target, kebutuhan pemadaman, peralatan, dan kompetensi yang dicatat."
            :requirements="['Master aset canonical dengan ID stabil', 'Kolom kebutuhan teknis pada WO khusus', 'Kode kebutuhan dari reference_codes']"
        />
        <x-admin.unavailable-panel
            title="Eskalasi Lintas Tim"
            description="Permintaan eskalasi menunggu keputusan Admin; setelah ACC, WO sumber menjadi SELESAI_DITERUSKAN dan child WO HARSUS dibuat secara atomik."
            :requirements="['Tabel work_escalations dan approvals', 'Transaksi ACC atomik beserta audit', 'Kode alasan eskalasi resmi']"
        >
            <a href="{{ route('admin.reviews.index') }}" class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800">Buka Review & Validasi →</a>
        </x-admin.unavailable-panel>
    </div>
</div>
@endsection
