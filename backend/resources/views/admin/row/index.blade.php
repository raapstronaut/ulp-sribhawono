@extends('layouts.admin')

@section('title', 'ROW')

@section('content')
@php
    $rowSummaryReady = ! $load->failed('summary');
    $rowVegetationReady = ! $load->failed('vegetation');
    $rowActive = $rowSummaryReady ? (int) ($summary['active'] ?? 0) : null;
    $rowCompleted = $rowSummaryReady ? (int) ($summary['completed_today'] ?? 0) : null;
    $rowOverdue = $rowSummaryReady ? (int) ($summary['overdue'] ?? 0) : null;
@endphp
<div class="mx-auto max-w-7xl space-y-5 pb-10">
    <x-admin.page-header
        section="Pekerjaan"
        title="ROW"
        state="partial"
        description="Monitoring pekerjaan vegetasi RABAS, PANGKAS, dan TEBANG: penugasan, status pelaksanaan, dan penyelesaian tim ROW."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.team-work-orders.create', 'row')">Buat WO ROW</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Ringkasan didasarkan pada database nyata, bukan data simulasi. --}}
    <section aria-label="Ringkasan ROW" class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Monitor ROW</span>
                    <span class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-700">WO manual tersedia</span>
                </div>
                <h2 class="mt-3 text-lg font-bold text-slate-900">Kendalikan pekerjaan vegetasi</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">Pantau WO ROW yang masih aktif, pekerjaan terlambat, dan hasil pekerjaan hari ini. Target vegetasi manual berasal dari data sumber yang masih berstatus staging.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:min-w-[380px]">
                <div class="rounded-lg border border-blue-100 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">WO aktif</div>
                    <div class="mt-1 text-2xl font-bold text-blue-700">{{ $rowActive ?? '-' }}</div>
                    <div class="mt-1 text-[10px] text-slate-500">Pekerjaan berjalan</div>
                </div>
                <div class="rounded-lg border border-blue-100 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Selesai hari ini</div>
                    <div class="mt-1 text-2xl font-bold text-emerald-700">{{ $rowCompleted ?? '-' }}</div>
                    <div class="mt-1 text-[10px] text-slate-500">Tanggal WIB</div>
                </div>
                <div class="col-span-2 rounded-lg border border-blue-100 bg-white p-3 sm:col-span-1">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Terlambat</div>
                    <div class="mt-1 text-2xl font-bold {{ $rowOverdue !== null && $rowOverdue > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $rowOverdue ?? '-' }}</div>
                    <div class="mt-1 text-[10px] text-slate-500">Melewati batas waktu</div>
                </div>
            </div>
        </div>
        <div class="mt-4 rounded-lg border border-blue-100 bg-white/80 px-3 py-2.5 text-xs leading-5 text-slate-600">
            <span class="font-semibold text-slate-800">Kesiapan modul:</span> Admin dapat membuat WO manual. Generator otomatis, paket rute, dan histori vegetasi berbasis data canonical belum tersedia.
        </div>
    </section>

    <details class="group overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50/70 px-4 py-3 text-xs font-semibold text-amber-900 [&::-webkit-details-marker]:hidden">
            <span>Aturan pekerjaan dan batasan data ROW</span>
            <span class="text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden text-amber-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="space-y-2 border-t border-amber-100 px-4 py-4 text-xs leading-6 text-slate-600">
            <p><strong class="text-slate-800">Pembuatan WO:</strong> Admin dapat membuat WO ROW manual dari target vegetasi pada data sumber (staging). Satu titik hanya boleh memiliki satu WO aktif untuk jenis pekerjaan yang sama.</p>
            <p><strong class="text-slate-800">Generator otomatis:</strong> Belum aktif. Formula risiko, siklus vegetasi, dan aturan paket/rute harian belum ditetapkan. Tidak ada WO otomatis yang dibuat oleh sistem pada kondisi ini.</p>
            <p><strong class="text-slate-800">Kualitas referensi:</strong> Data vegetasi sumber belum menjadi master aset vegetasi canonical. Jangan menyamakan baris staging dengan jumlah WO selesai.</p>
        </div>
    </details>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <section aria-labelledby="row-jenis-title" class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="row-jenis-title" class="text-sm font-bold text-slate-900">Pekerjaan aktif menurut jenis</h2>
                    <p class="mt-1 text-xs text-slate-500">Ringkasan WO RABAS, PANGKAS, dan TEBANG yang masih berjalan.</p>
                </div>
                <span class="text-xs font-semibold text-slate-500">Total aktif: {{ $summary['active'] }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($workTypeOptions as $workType)
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ $workType }}</div>
                        <div class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['by_work_type'][$workType] ?? 0 }}</div>
                        <div class="mt-1 text-xs text-slate-500">WO {{ $workType }} aktif</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <x-admin.panel flush title="Daftar WO ROW" description="Cari, filter, dan buka detail pekerjaan. Halaman detail menampilkan audit, laporan, dan bukti foto yang sudah tercatat.">
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
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5 text-xs text-slate-500">
                <span><strong class="text-slate-800">{{ $workOrders->total() }}</strong> WO cocok dengan filter</span>
                <a href="{{ route('admin.team-work-orders.create', 'row') }}" class="font-semibold text-blue-700 hover:text-blue-900">Buat WO manual</a>
            </div>
            <x-admin.work-order-table
                :work-orders="$workOrders"
                empty-title="Belum ada WO ROW."
                empty-description="Belum ada WO ROW tersimpan atau tidak ada data yang cocok dengan filter. Generator ROW otomatis belum aktif."
            />
            <x-admin.pagination :paginator="$workOrders" label="WO ROW" />
        @endif
    </x-admin.panel>

    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
            <span>Referensi vegetasi dan kesiapan fitur lanjutan</span>
            <span class="text-xs text-blue-700 group-open:hidden">Buka +</span>
            <span class="hidden text-xs text-blue-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="space-y-4 border-t border-slate-100 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-3">
                <div>
                    <p class="text-xs font-semibold text-slate-800">Data vegetasi sumber (staging)</p>
                    <p class="mt-1 text-xs text-slate-500">Baris sumber untuk penentuan target WO manual; belum menjadi master vegetasi canonical.</p>
                </div>
                <div class="text-right">
                    <div class="text-xl font-bold text-slate-900">{{ $rowVegetationReady ? number_format((int) $vegetationSourceRows, 0, ',', '.') : '-' }}</div>
                    <a href="{{ route('admin.master-data.index', ['tab' => 'sumber']) }}" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Buka Master Data</a>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <x-admin.unavailable-panel
                    title="Paket / Rute Harian"
                    description="Pengelompokan WO ROW yang berdekatan ke paket rute harian menunggu aturan dan referensi koordinat yang tervalidasi."
                    :requirements="['Tabel work_routes dan work_route_stops', 'Koordinat vegetasi tervalidasi', 'Aturan pengelompokan dan rotasi area (TBD)']"
                />
                <x-admin.unavailable-panel
                    title="Histori Vegetasi"
                    description="Riwayat tindakan pada titik vegetasi menunggu master canonical dan event histori aset."
                    :requirements="['Tabel vegetations canonical dari staging tervalidasi', 'Evaluasi risiko vegetasi (formula TBD)', 'Event histori aset append-only']"
                />
            </div>
        </div>
    </details>
</div>
@endsection
