
@extends('layouts.admin')

@section('title', 'HARSUS')

@section('content')
@php
    $harsusSummaryReady = ! $load->failed('summary');

    $harsusActive = $harsusSummaryReady
        ? (int) ($summary['active'] ?? 0)
        : null;

    $harsusManual = $harsusSummaryReady
        ? (int) ($summary['by_source'][\App\Models\WorkOrder::SOURCE_ADMIN_MANUAL] ?? 0)
        : null;

    $harsusLimpahan = $harsusSummaryReady
        ? (int) ($summary['by_source'][\App\Models\WorkOrder::SOURCE_ESCALATION] ?? 0)
        : null;

    $harsusCompleted = $harsusSummaryReady
        ? (int) ($summary['completed_today'] ?? 0)
        : null;
@endphp

<div class="pln-operations-workspace mx-auto max-w-7xl space-y-3 pb-8">
    <x-admin.page-header
        section="Pekerjaan"
        title="HARSUS"
        state="partial"
        description="Pantau WO pekerjaan khusus dari Admin dan limpahan lintas tim, termasuk penugasan dan penyelesaiannya."
    >
        <x-slot:actions>
            <x-admin.button :href="route('admin.team-work-orders.create', 'harsus')">
                Buat Pekerjaan Khusus
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Ringkasan berdasarkan WO nyata, bukan usulan eskalasi. --}}
    <section
        aria-label="Ringkasan HARSUS"
        class="overflow-hidden rounded-xl border border-blue-200 bg-blue-50/50 p-3 shadow-sm sm:p-4"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">
                    Monitor HARSUS
                </span>

                <span class="text-xs font-semibold text-slate-700">
                    Pekerjaan khusus jaringan
                </span>

                <span class="rounded-md border border-blue-100 bg-white px-2 py-1 text-[10px] font-semibold text-blue-700">
                    WO manual tersedia
                </span>
            </div>

            <span class="text-[11px] font-medium text-amber-700">
                Eskalasi otomatis belum tersedia
            </span>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    WO khusus aktif
                </p>
                <p class="mt-1 text-2xl font-bold text-blue-700">
                    {{ $harsusActive ?? '—' }}
                </p>
                <p class="text-[10px] text-slate-500">
                    Pekerjaan masih aktif
                </p>
            </div>

            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    Dari Admin
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900">
                    {{ $harsusManual ?? '—' }}
                </p>
                <p class="text-[10px] text-slate-500">
                    WO manual yang masih aktif
                </p>
            </div>

            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    Limpahan
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900">
                    {{ $harsusLimpahan ?? '—' }}
                </p>
                <p class="text-[10px] text-slate-500">
                    Child WO eskalasi tercatat
                </p>
            </div>

            <div class="rounded-lg border border-blue-100 bg-white px-3 py-2.5">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    Selesai hari ini
                </p>
                <p class="mt-1 text-2xl font-bold text-emerald-700">
                    {{ $harsusCompleted ?? '—' }}
                </p>
                <p class="text-[10px] text-slate-500">
                    Menurut tanggal WIB
                </p>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-blue-100 pt-3 text-xs">
            <span class="text-slate-600">
                WO manual memerlukan target KEYPOINT yang sah.
            </span>
            <span class="font-medium text-amber-700">
                Master aset canonical belum tersedia
            </span>
        </div>
    </section>

    @if ($load->failed('summary'))
        <x-admin.error-state :inset="false" />
    @endif

    <x-admin.panel
        class="pln-operations-list"
        flush
        title="Daftar WO Pekerjaan Khusus"
        description="Cari pekerjaan, filter asal dan status, lalu buka detail, laporan, atau riwayat audit."
    >
        <x-admin.filter-bar
            :action="route('admin.harsus.index')"
            :reset-url="route('admin.harsus.index')"
            :active="$hasFilter" title="PENCARIAN & FILTER HARSUS" button-label="Search" :always-reset="true" class="pln-operations-filter"
        >
            <x-admin.filter-input
                name="q"
                label="Cari"
                :value="$filters['q']"
                placeholder="Kode WO atau Section"
            />

            <x-admin.filter-select
                name="source"
                label="Sumber"
                :options="$sourceOptions"
                :value="$filters['source']"
                placeholder="Semua sumber"
            />

            <x-admin.filter-select
                name="status"
                label="Status"
                :options="$statusOptions"
                :value="$filters['status']"
                placeholder="Semua status"
            />

            <x-admin.filter-select
                name="penyulang_id"
                label="Penyulang"
                :options="$penyulangOptions"
                :value="$filters['penyulang_id']"
                placeholder="Semua penyulang"
            />

            <x-admin.filter-input
                type="date"
                name="work_date"
                label="Tanggal kerja"
                :value="$filters['work_date']"
            />
        </x-admin.filter-bar>

        @if ($load->failed('work_orders'))
            <x-admin.error-state />
        @else
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
                <span>
                    <strong class="text-slate-800">
                        {{ $workOrders->total() }}
                    </strong>
                    WO cocok dengan filter
                </span>

                <a
                    href="{{ route('admin.team-work-orders.create', 'harsus') }}"
                    class="font-semibold text-blue-700 hover:text-blue-900"
                >
                    Buat WO manual
                </a>
            </div>

            @if ($workOrders->count() === 0)
                <div class="px-4 py-4 text-sm text-slate-600">
                    <p class="font-semibold text-slate-800">
                        Belum ada WO Pekerjaan Khusus.
                    </p>

                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        Belum ada WO HARSUS tersimpan atau tidak ada data
                        yang cocok dengan filter.
                    </p>
                </div>
            @else
                <x-admin.work-order-table
                    :work-orders="$workOrders"
                    empty-title="Belum ada WO Pekerjaan Khusus."
                    empty-description="Belum ada WO HARSUS tersimpan atau tidak ada data yang cocok dengan filter."
                />
            @endif

            <x-admin.pagination
                :paginator="$workOrders"
                label="WO HARSUS"
            />
        @endif
    </x-admin.panel>

    <details class="group overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50/60 px-4 py-3 text-xs font-semibold text-amber-900 [&::-webkit-details-marker]:hidden">
            <span>Aturan pekerjaan dan batasan operasional HARSUS</span>
            <span class="text-amber-700 group-open:hidden">Lihat aturan +</span>
            <span class="hidden text-amber-700 group-open:inline">Tutup -</span>
        </summary>

        <div class="space-y-2 border-t border-amber-100 px-4 py-3 text-xs leading-6 text-slate-600">
            <p>
                <strong class="text-slate-800">HARSUS bukan pelaksana P0.</strong>
                P0 tetap pekerjaan POSKO pada sesi PASILA.
            </p>

            <p>
                <strong class="text-slate-800">WO manual:</strong>
                Admin dapat membuat pekerjaan khusus menggunakan target
                KEYPOINT yang sah. Tim pelaksana dan kesiapan aset perlu
                diperiksa sebelum digunakan di lapangan.
            </p>

            <p>
                <strong class="text-slate-800">Eskalasi lintas tim:</strong>
                Memerlukan persetujuan Admin. Pembuatan child WO otomatis
                dari eskalasi belum tersedia.
            </p>

            <p>
                Cuaca buruk atau akses terhalang tidak otomatis memindahkan
                pekerjaan ke HARSUS. Gunakan alur pending atau reschedule
                sesuai aturan pekerjaan.
            </p>

            <a
                href="{{ route('admin.reviews.index') }}"
                class="inline-block font-semibold text-blue-700 hover:underline"
            >
                Buka Review &amp; Validasi
            </a>
        </div>
    </details>

    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
            <span>Referensi aset dan kesiapan fitur lanjutan</span>
            <span class="text-xs text-blue-700 group-open:hidden">Buka +</span>
            <span class="hidden text-xs text-blue-700 group-open:inline">Tutup -</span>
        </summary>

        <div class="grid gap-4 border-t border-slate-100 p-4 lg:grid-cols-2">
            <x-admin.unavailable-panel
                title="Aset Target &amp; Kebutuhan Teknis"
                description="Setiap WO khusus akan menampilkan aset target, kebutuhan pemadaman, peralatan, dan kompetensi setelah datanya tersedia."
                :requirements="[
                    'Master aset canonical dengan ID stabil',
                    'Kolom kebutuhan teknis pada WO khusus',
                    'Kode kebutuhan dari reference_codes'
                ]"
            />

            <x-admin.unavailable-panel
                title="Eskalasi Lintas Tim"
                description="Permintaan eskalasi memerlukan keputusan Admin. Alur child WO HARSUS otomatis belum tersedia."
                :requirements="[
                    'Tabel work_escalations dan approvals',
                    'Transaksi ACC atomik beserta audit',
                    'Kode alasan eskalasi resmi'
                ]"
            >
                <a
                    href="{{ route('admin.reviews.index') }}"
                    class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800"
                >
                    Buka Review &amp; Validasi
                </a>
            </x-admin.unavailable-panel>
        </div>
    </details>
</div>
@endsection