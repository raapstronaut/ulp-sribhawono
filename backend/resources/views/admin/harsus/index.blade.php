@extends('layouts.admin')

@section('title', 'HARSUS')

@section('content')
<div class="mx-auto max-w-7xl space-y-5 pb-10">
    <x-admin.page-header
        section="Pekerjaan"
        title="HARSUS"
        state="partial"
        description="Monitor pekerjaan khusus dari Admin dan pekerjaan lintas tim yang telah disetujui. P0 tetap pekerjaan POSKO di PASILA."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.team-work-orders.create', 'harsus')">Buat Pekerjaan Khusus</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <section aria-label="Ringkasan modul HARSUS" class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap gap-2 text-[10px] font-bold">
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-white">MONITOR HARSUS</span>
                    <span class="rounded-md border border-blue-200 bg-white px-2.5 py-1 text-slate-600">WO pekerjaan khusus</span>
                </div>
                <h2 class="mt-3 text-lg font-bold text-slate-900">Kendalikan pekerjaan khusus jaringan</h2>
                <p class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-600">
                    Pantau penugasan, asal pekerjaan, dan penyelesaian HARSUS. Pekerjaan manual berasal dari aset KEYPOINT;
                    eskalasi dari tim lain baru dapat menghasilkan WO setelah alur persetujuannya tersedia.
                </p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:w-[360px]">
                <div class="rounded-lg border border-blue-100 bg-white p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">WO manual</p>
                    <p class="mt-1 text-sm font-semibold text-blue-700">Dapat dibuat Admin</p>
                    <p class="mt-1 text-[11px] text-slate-500">Memerlukan target aset KEYPOINT yang sah</p>
                </div>
                <div class="rounded-lg border border-amber-200 bg-white p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Eskalasi lintas tim</p>
                    <p class="mt-1 text-sm font-semibold text-amber-700">Belum tersedia</p>
                    <p class="mt-1 text-[11px] text-slate-500">Belum ada alur ACC dan child WO otomatis</p>
                </div>
            </div>
        </div>
        <p class="mt-4 rounded-lg border border-blue-100 bg-white/80 px-3 py-2 text-xs text-slate-600">
            <strong class="text-slate-800">Batasan operasional:</strong> Tim pelaksana dan kesiapan aset harus diperiksa sebelum WO dipakai di lapangan.
            Master aset canonical dan alur eskalasi masih memerlukan penyelesaian.
        </p>
    </section>

    <details class="group overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50/70 px-4 py-3 text-xs font-semibold text-amber-900">
            <span>Aturan HARSUS dan kesiapan data</span>
            <span class="shrink-0 text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden shrink-0 text-amber-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="space-y-2 border-t border-amber-100 px-4 py-4 text-xs leading-relaxed text-slate-700">
            <p><strong>HARSUS bukan pelaksana P0.</strong> P0 tetap pekerjaan POSKO pada sesi PASILA.</p>
            <p>Cuaca buruk atau akses terhalang bukan alasan otomatis untuk memindahkan pekerjaan ke HARSUS; gunakan alur pending atau reschedule sesuai aturan pekerjaan.</p>
            <p>Limpahan lintas tim membutuhkan persetujuan Admin di Review &amp; Validasi. Pembuatan child WO dari eskalasi belum tersedia di modul ini.</p>
            <p>WO HARSUS manual memakai target KEYPOINT hasil sinkronisasi, sementara master aset jaringan canonical belum tersedia.</p>
            <a href="{{ route('admin.reviews.index') }}" class="inline-block font-semibold text-blue-700 hover:underline">Buka Review &amp; Validasi</a>
        </div>
    </details>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @else
        <section aria-label="Statistik HARSUS" class="space-y-2">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Ringkasan pekerjaan HARSUS</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Jumlah WO nyata pada database, bukan usulan eskalasi.</p>
                </div>
                <span class="text-xs font-semibold text-slate-500">Aktif: {{ $summary['active'] }} WO</span>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-admin.stat-card label="WO khusus aktif" :value="$summary['active']" hint="Belum selesai, diteruskan, atau dibatalkan" tone="blue" />
                <x-admin.stat-card label="Dari Admin" :value="$summary['by_source'][\App\Models\WorkOrder::SOURCE_ADMIN_MANUAL] ?? 0" hint="WO manual Admin yang masih aktif" />
                <x-admin.stat-card label="Limpahan" :value="$summary['by_source'][\App\Models\WorkOrder::SOURCE_ESCALATION] ?? 0" hint="Child WO hasil eskalasi yang masih aktif" />
                <x-admin.stat-card label="Selesai hari ini" :value="$summary['completed_today']" hint="Status selesai hari ini menurut WIB" tone="emerald" />
            </div>
        </section>
    @endif

    <x-admin.panel flush title="Daftar WO Pekerjaan Khusus" description="Cari dan filter pekerjaan. Detail WO memuat status, pelaksana, laporan, dan audit; kolom asal menautkan WO sumber bila ada eskalasi.">
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
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-xs">
                <span class="font-semibold text-slate-700">{{ $workOrders->total() }} WO cocok dengan filter</span>
                <a href="{{ route('admin.team-work-orders.create', 'harsus') }}" class="font-semibold text-blue-600 hover:text-blue-800">Buat WO manual</a>
            </div>
            <x-admin.work-order-table
                :work-orders="$workOrders"
                empty-title="Belum ada WO Pekerjaan Khusus."
                empty-description="Belum ada WO HARSUS tersimpan atau tidak ada data yang cocok dengan filter."
            />
            <x-admin.pagination :paginator="$workOrders" label="WO HARSUS" />
        @endif
    </x-admin.panel>

    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
            <span>Referensi aset dan kesiapan fitur lanjutan</span>
            <span class="shrink-0 text-xs font-semibold text-blue-700 group-open:hidden">Buka +</span>
            <span class="hidden shrink-0 text-xs font-semibold text-blue-700 group-open:inline">Tutup -</span>
        </summary>
        <div class="grid gap-4 border-t border-slate-100 p-4 lg:grid-cols-2">
            <x-admin.unavailable-panel
                title="Aset Target &amp; Kebutuhan Teknis"
                description="Setiap WO khusus akan menampilkan aset target, kebutuhan pemadaman, peralatan, dan kompetensi setelah datanya tersedia."
                :requirements="['Master aset canonical dengan ID stabil', 'Kolom kebutuhan teknis pada WO khusus', 'Kode kebutuhan dari reference_codes']"
            />
            <x-admin.unavailable-panel
                title="Eskalasi Lintas Tim"
                description="Permintaan eskalasi memerlukan keputusan Admin. Alur child WO HARSUS otomatis belum tersedia."
                :requirements="['Tabel work_escalations dan approvals', 'Transaksi ACC atomik beserta audit', 'Kode alasan eskalasi resmi']"
            >
                <a href="{{ route('admin.reviews.index') }}" class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800">Buka Review &amp; Validasi</a>
            </x-admin.unavailable-panel>
        </div>
    </details>
</div>
@endsection
