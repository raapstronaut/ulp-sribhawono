@extends('layouts.admin')

@section('title', 'ROW')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="ROW"
        state="partial"
        description="WO vegetasi RABAS, PANGKAS, dan TEBANG untuk tim ROW. WO ROW otomatis aktif tanpa ACC Admin dan dapat berjumlah banyak per hari."
    >
        <x-slot:actions>
            <x-admin.unavailable-action label="Buat WO ROW" reason="Pembuatan WO ROW manual membutuhkan data vegetasi canonical dan validasi lokasi; belum dibangun." />
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice title="Status modul:">
        Daftar di bawah membaca tabel <code>work_orders</code> (team_type ROW) secara langsung. Generator WO ROW otomatis, formula risiko/siklus vegetasi, aturan duplikasi, dan paket rute harian masih TBD sehingga belum ada WO ROW yang dibuat sistem.
    </x-admin.notice>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.stat-card label="WO ROW Aktif" :value="$summary['active']" hint="Belum selesai, diteruskan, atau dibatalkan" />
            @foreach ($workTypeOptions as $workType)
                <x-admin.stat-card :label="$workType.' aktif'" :value="$summary['by_work_type'][$workType] ?? 0" :hint="'WO '.$workType.' yang masih berjalan'" />
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-admin.stat-card label="Selesai hari ini" :value="$summary['completed_today']" hint="Status SELESAI pada tanggal WIB berjalan" tone="emerald" />
            <x-admin.stat-card label="Terlambat" :value="$summary['overdue']" hint="WO aktif melewati batas waktu" :tone="$summary['overdue'] > 0 ? 'red' : 'slate'" />
            <x-admin.stat-card label="Baris vegetasi sumber" :value="$load->failed('vegetation') ? null : $vegetationSourceRows" hint="Staging mentah vegetasi_source_rows, belum canonical; tidak dipakai sebagai target WO." :href="route('admin.master-data.index', ['tab' => 'sumber'])" />
        </div>
    @endif

    <x-admin.panel flush title="Daftar WO ROW" description="Detail membuka halaman WO generik beserta audit, laporan, dan bukti foto.">
        <x-admin.filter-bar :action="route('admin.row.index')" :reset-url="route('admin.row.index')" :active="$hasFilter">
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" placeholder="Kode WO atau Section" />
            <x-admin.filter-select name="work_type" label="Jenis" :options="$workTypeOptions" :value="$filters['work_type']" placeholder="Semua jenis" />
            <x-admin.filter-select name="status" label="Status" :options="$statusOptions" :value="$filters['status']" placeholder="Semua status" />
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$filters['penyulang_id']" placeholder="Semua penyulang" />
            <x-admin.filter-input type="date" name="work_date" label="Tanggal kerja" :value="$filters['work_date']" />
        </x-admin.filter-bar>

        @if ($load->failed('work_orders'))
            <x-admin.error-state />
        @else
            <x-admin.work-order-table
                :work-orders="$workOrders"
                empty-title="Belum ada WO ROW."
                empty-description="Belum ada WO ROW tersimpan atau tidak ada data yang cocok dengan filter. Generator otomatis ROW belum aktif."
            />
            <x-admin.pagination :paginator="$workOrders" label="WO ROW" />
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Paket / Rute Harian"
            description="WO ROW berdekatan akan dikelompokkan dalam paket rute harian yang bergilir menurut Section/area."
            :requirements="['Tabel work_routes dan work_route_stops', 'Koordinat vegetasi tervalidasi', 'Aturan pengelompokan dan rotasi area (TBD)']"
        />
        <x-admin.unavailable-panel
            title="Histori Vegetasi"
            description="Histori tindakan per titik vegetasi akan tampil setelah vegetasi canonical dan event histori aset tersedia."
            :requirements="['Tabel vegetations canonical dari staging tervalidasi', 'Evaluasi risiko vegetasi (formula TBD)', 'Event histori aset append-only']"
        />
    </div>
</div>
@endsection
