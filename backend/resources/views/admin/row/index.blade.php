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
<div class="mx-auto max-w-7xl space-y-3 pb-8">
    <x-admin.page-header
        section="Pekerjaan"
        title="ROW"
        state="partial"
        description="Pantau WO RABAS, PANGKAS, dan TEBANG, termasuk pelaksanaan, keterlambatan, dan hasil kerja tim ROW."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.team-work-orders.create', 'row')">Buat WO ROW</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Ringkasan hanya dari WO yang benar-benar tersimpan, bukan hasil staging atau simulasi. --}}
    <section aria-label="Ringkasan ROW" class="overflow-hidden rounded-xl border border-blue-200 bg-blue-50/50 p-3 shadow-sm sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Monitor ROW</span>
                <span class="text-xs font-semibold text-slate-700">Pekerjaan vegetasi</span>
                <span class="rounded-md border border-blue-100 bg-white px-2 py-1 text-[10px] font-semibold text-blue-700">WO manual tersedia</span>
            </div>
            <span class="text-[11px] text-amber-700">Generator otomatis belum aktif</span>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">WO aktif</div>
                <div class="mt-1 text-2xl font-bold text-blue-700">{{ $rowActive ?? '-' }}</div>
                <div class="text-[10px] text-slate-500">Pekerjaan berjalan</div>
            </div>
            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Selesai hari ini</div>
                <div class="mt-1 text-2xl font-bold text-emerald-700">{{ $rowCompleted ?? '-' }}</div>
                <div class="text-[10px] text-slate-500">Menurut tanggal WIB</div>
            </div>
            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Terlambat</div>
                <div class="mt-1 text-2xl font-bold {{ $rowOverdue !== null && $rowOverdue > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $rowOverdue ?? '-' }}</div>
                <div class="text-[10px] text-slate-500">Melewati batas waktu</div>
            </div>
        </div>

        @if ($rowSummaryReady)
            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-blue-100 pt-3 text-xs">
                <span class="mr-1 font-semibold text-slate-600">WO aktif per jenis:</span>
                @foreach ($workTypeOptions as $workType)
                    <span class="inline-flex items-center gap-2 rounded-md border border-blue-100 bg-white px-2.5 py-1.5 text-slate-700">
                        <span class="font-semibold">{{ $workType }}</span>
                        <span class="font-bold text-blue-700">{{ $summary['by_work_type'][$workType] ?? 0 }}</span>
                    </span>
                @endforeach
            </div>
        @endif
    </section>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @endif

    <x-admin.panel flush title="Daftar WO ROW" description="Cari pekerjaan, filter status dan jadwal, lalu buka detail atau bukti laporannya.">
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
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
                <span><strong class="text-slate-800">{{ $workOrders->total() }}</strong> WO cocok dengan filter</span>
                <a href="{{ route('admin.team-work-orders.create', 'row') }}" class="font-semibold text-blue-700 hover:text-blue-900">Buat WO manual</a>
            </div>
            @if ($workOrders->count() === 0)
                {{-- Kosong: tidak memakan tinggi layar seperti tabel + empty-state besar. --}}
                <div class="px-4 py-4 text-sm text-slate-600">
                    <p class="font-semibold text-slate-800">Belum ada WO ROW.</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Belum ada WO ROW tersimpan atau tidak ada data yang cocok dengan filter. Generator otomatis belum aktif.</p>
                </div>
            @else
                <x-admin.work-order-table
                    :work-orders="$workOrders"
                    empty-title="Belum ada WO ROW."
                    empty-description="Belum ada WO ROW tersimpan atau tidak ada data yang cocok dengan filter. Generator otomatis belum aktif."
                />
                <x-admin.pagination :paginator="$workOrders" label="WO ROW" />
            @endif
        @endif
    </x-admin.panel>

    <details class="group overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50/60 px-4 py-3 text-xs font-semibold text-amber-900 [&::-webkit-details-marker]:hidden">
            <span>Aturan pekerjaan dan batasan data ROW</span>
            <span class="text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden text-amber-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="space-y-2 border-t border-amber-100 px-4 py-3 text-xs leading-6 text-slate-600">
            <p><strong class="text-slate-800">Pembuatan WO:</strong> Admin dapat membuat WO ROW manual dari target vegetasi staging. Satu titik hanya boleh memiliki satu WO aktif untuk jenis pekerjaan yang sama.</p>
            <p><strong class="text-slate-800">Generator otomatis:</strong> Belum aktif. Formula risiko, siklus vegetasi, serta aturan paket/rute harian belum ditetapkan. Tidak ada WO otomatis yang dibuat pada kondisi ini.</p>
            <p><strong class="text-slate-800">Kualitas referensi:</strong> Data vegetasi sumber belum menjadi master aset vegetasi canonical. Baris staging tidak boleh dianggap jumlah WO selesai.</p>
        </div>
    </details>

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