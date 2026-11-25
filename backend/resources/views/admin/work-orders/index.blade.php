@extends('layouts.admin')

@section('title', 'Work Order APKUK')

@section('content')
@php
    $statusClasses = [
        'DITUGASKAN' => 'bg-blue-50 text-blue-700 border-blue-200',
        'DIKERJAKAN' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'MENUNGGU_VERIFIKASI' => 'bg-amber-50 text-amber-700 border-amber-200',
        'RESCHEDULE_DIAJUKAN' => 'bg-amber-50 text-amber-700 border-amber-200',
        'DIJADWALKAN_ULANG' => 'bg-sky-50 text-sky-700 border-sky-200',
        'SELESAI' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'SELESAI_DITERUSKAN' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'DIBATALKAN' => 'bg-slate-100 text-slate-500 border-slate-200',
    ];
    $hasFilter = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty();
@endphp
<div class="pln-operations-workspace max-w-7xl mx-auto space-y-6">
    <x-admin.posko-tabs />

    <!-- Header monitoring WO APKUK -->
    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div class="min-w-0 space-y-1">
            <nav aria-label="Breadcrumb" class="text-xs font-semibold text-slate-500">Pekerjaan <span aria-hidden="true">/</span> POSKO <span aria-hidden="true">/</span> <span class="text-blue-700">WO APKUK</span></nav>
            <h1 class="text-2xl font-black tracking-tight text-slate-900">Work Order APKUK</h1>
            <p class="max-w-3xl text-sm text-slate-600">Pantau penugasan, pelaksanaan dan tindak lanjut pemasangan APKUK oleh enam POSKO.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.shift-quota.index') }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Lihat Kuota Shift</a>
            <a href="{{ route('admin.work-orders.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700"><span aria-hidden="true">+</span> Buat WO APKUK</a>
        </div>
    </div>
    <!-- Konteks shift selalu memakai hari operasional WIB -->
    <section aria-label="Konteks shift POSKO" class="flex flex-col gap-3 rounded-xl border border-blue-100 bg-blue-50 px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <p class="text-sm font-bold text-blue-950">Shift {{ $shift_name }} · {{ $shift_time }} WIB</p>
            <p class="text-xs text-blue-800">Tanggal operasional: <strong>{{ \Illuminate\Support\Carbon::parse($operational_date)->format('d/m/Y') }}</strong> · Acuan {{ $target_per_shift }} WO per POSKO / shift, total {{ $target_total }} WO se-ULP.</p>
        </div>
        <p class="max-w-sm text-xs text-blue-800">Satu WO = satu pekerjaan. Jumlah APKUK fisik dicatat terpisah dalam laporan. Alokasi dan limpahan sah dilihat di Kuota Shift.</p>
    </section>
    <!-- Empat KPI yang disepakati; label cakupan tidak boleh rancu -->
    <section aria-label="Ringkasan monitoring WO APKUK" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">WO Shift Ini</div>
            <div class="mt-2 text-3xl font-black tabular-nums text-slate-900">{{ $wo_shift_ini }}</div>
            <p class="mt-1 text-xs text-slate-500">Dari acuan {{ $target_total }} WO · tidak termasuk batal</p>
        </div>
        <a href="{{ route('admin.work-orders.index', ['status' => \App\Enums\WorkOrderStatus::Dikerjakan->value, 'work_date' => $operational_date, 'shift_code' => $current_shift_code]) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:shadow-md">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Sedang Dikerjakan</div>
            <div class="mt-2 text-3xl font-black tabular-nums text-blue-700">{{ $wo_dikerjakan }}</div>
            <p class="mt-1 text-xs text-slate-500">Hanya shift berjalan · klik untuk rinciannya</p>
        </a>
        <a href="{{ route('admin.work-orders.index', ['status' => \App\Enums\WorkOrderStatus::MenungguVerifikasi->value]) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-amber-300 hover:shadow-md">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Perlu Verifikasi</div>
            <div class="mt-2 text-3xl font-black tabular-nums {{ $wo_perlu_verifikasi > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $wo_perlu_verifikasi }}</div>
            <p class="mt-1 text-xs text-slate-500">Antrean laporan seluruh shift · tindak lanjut melalui Review</p>
        </a>
        <a href="{{ route('admin.work-orders.index', ['status' => \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE]) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-rose-300 hover:shadow-md">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Terlambat</div>
            <div class="mt-2 text-3xl font-black tabular-nums {{ $wo_terlambat > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ $wo_terlambat }}</div>
            <p class="mt-1 text-xs text-slate-500">WO aktif melewati batas shift · seluruh periode</p>
        </a>
    </section>
    <!-- Beban kerja per POSKO: batang = WO terjadwal, angka selesai terpisah -->
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="posko-progress-heading">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="posko-progress-heading" class="text-base font-bold text-slate-900">Progres per POSKO</h2>
                <p class="mt-1 text-xs text-slate-500">Pekerjaan nyata yang dijadwalkan untuk shift berjalan, dibandingkan acuan {{ $target_per_shift }} WO per POSKO.</p>
            </div>
            <a href="{{ route('admin.shift-quota.index') }}" class="shrink-0 text-xs font-bold text-blue-700 hover:underline">Rincian alokasi & limpahan →</a>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach($poskos as $posko)
                @php
                    $progress = $posko_progress[$posko->id];
                    $percent = $target_per_shift > 0 ? min(100, (int) round($progress['total'] / $target_per_shift * 100)) : 0;
                @endphp
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <span class="truncate text-xs font-bold text-slate-800" title="{{ $posko->nama_posko }}">{{ $posko->nama_posko }}</span>
                        <span class="whitespace-nowrap text-xs font-black tabular-nums text-slate-900">{{ $progress['total'] }}/{{ $target_per_shift }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="WO terjadwal {{ $posko->nama_posko }}" aria-valuenow="{{ min($progress['total'], $target_per_shift) }}" aria-valuemin="0" aria-valuemax="{{ $target_per_shift }}">
                        <div class="h-full rounded-full bg-blue-600" style="width: {{ $percent }}%"></div>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-slate-600"><span>{{ $progress['selesai'] }} selesai</span><span>{{ $progress['aktif'] }} aktif</span></div>
                    @if($progress['total'] > $target_per_shift)
                        <div class="mt-2 text-[10px] font-semibold text-amber-700">Di atas acuan · cek limpahan sah</div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    <!-- Daftar dan tindak lanjut WO APKUK -->
    <section class="pln-operations-list rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="wo-list-heading">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="wo-list-heading" class="text-base font-bold text-slate-900">Daftar Work Order</h2>
                <p class="mt-1 text-xs text-slate-500">Cari pekerjaan, buka detail, atau periksa antrean yang membutuhkan tindakan.</p>
            </div>
            <a href="{{ route('admin.reviews.index') }}" class="text-xs font-bold text-blue-700 hover:underline">Review & Validasi →</a>
        </div>
        <!-- Filter Bar -->
        <form method="GET" action="{{ route('admin.work-orders.index') }}" class="pln-operations-filter border-b border-slate-200">
            <h3 class="pln-operations-filter-title">PENCARIAN &amp; FILTER POSKO</h3>
            <div class="pln-operations-filter-fields">
                <label>
                    <span>Cari</span>
                    <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Cari kode WO atau no. tiang...">
                </label>
                <label>
                    <span>Status</span>
                    <select name="status" aria-label="Status">
                        <option value="">Semua Status</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                        <option value="{{ \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE }}" @selected($filters['status'] === \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE)>Terlambat</option>
                    </select>
                </label>
                <label>
                    <span>Posko</span>
                    <select name="posko_id" aria-label="Posko">
                        <option value="">Semua Posko</option>
                        @foreach($poskos as $posko)
                            <option value="{{ $posko->id }}" @selected($filters['posko_id'] === $posko->id)>{{ $posko->nama_posko }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Shift</span>
                    <select name="shift_code" aria-label="Shift">
                        <option value="">Semua Shift</option>
                        @foreach($shift_options as $option)
                            <option value="{{ $option['code'] }}" @selected($filters['shift_code'] === $option['code'])>Shift {{ $option['code'] }} ({{ $option['name'] }})</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Tanggal operasional</span>
                    <input type="date" name="work_date" value="{{ $filters['work_date'] }}" aria-label="Tanggal operasional">
                </label>
            </div>
            <div class="pln-operations-filter-actions">
                <button type="submit">Search</button>
                <a href="{{ route('admin.work-orders.index') }}">Reset</a>
            </div>
        </form>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600 min-w-[1000px]">
                <thead class="bg-white text-[10px] uppercase font-bold text-slate-500 border-b border-slate-200 tracking-wider">
                    <tr>
                        <th class="px-6 py-4">WO</th>
                        <th class="px-6 py-4">POSKO</th>
                        <th class="px-6 py-4">TIANG / SECTION</th>
                        <th class="px-6 py-4">PENYULANG</th>
                        <th class="px-6 py-4 text-center">TARGET</th>
                        <th class="px-6 py-4">JADWAL</th>
                        <th class="px-6 py-4">STATUS</th>
                        <th class="px-6 py-4 text-right">PEMBARUAN</th>
                        <th class="px-6 py-4 text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($work_orders as $wo)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $wo->code }}</div>
                                <div class="text-[10px] text-slate-500">{{ $wo->source === \App\Models\WorkOrder::SOURCE_ADMIN_MANUAL ? 'Manual Admin' : $wo->source }}</div>
                            </td>
                            <td class="px-6 py-4">{{ $wo->posko?->nama_posko ?? '-' }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-slate-800">{{ $wo->apkukTarget?->no_tiang ?? '-' }}</div>
                                <div class="text-[10px] text-slate-500">{{ $wo->section_name ?? 'Section belum diketahui' }}</div>
                            </td>
                            <td class="px-6 py-4">{{ $wo->penyulang?->nama_penyulang ?? '-' }}</td>
                            <td class="px-6 py-4 text-center">
                                <span class="font-semibold text-slate-800">{{ $wo->apkukTarget?->target_quantity ?? '-' }}</span>
                                <span class="text-[10px] text-slate-500">bh</span>
                            </td>
                            <td class="px-6 py-4">
                                <div>{{ $wo->work_date?->format('d/m/Y') }}</div>
                                <div class="text-[10px] text-slate-500">Shift {{ $wo->shift_code ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-bold {{ $statusClasses[$wo->status->value] ?? '' }}">{{ $wo->status->label() }}</span>
                                @if($wo->isOverdue())
                                    <span class="ml-1 inline-flex items-center rounded-md border border-red-200 bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">Terlambat</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-xs">{{ $wo->updated_at?->timezone(\App\Services\ShiftService::TIMEZONE)->format('d/m/Y H:i') }} WIB</td>
                            <td class="px-6 py-4 text-center">
                                <a href="{{ route('admin.work-orders.show', $wo) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4 border border-slate-100">
                                        <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                                    </div>
                                    <h3 class="text-base font-bold text-slate-700 mb-1">{{ $hasFilter ? 'Tidak ada WO yang cocok.' : 'Belum ada Work Order.' }}</h3>
                                    <p class="text-xs text-slate-500 max-w-sm">{{ $hasFilter ? 'Coba ubah atau reset filter untuk melihat pekerjaan lain.' : 'Belum ada WO APKUK yang tersimpan. Gunakan Buat WO APKUK untuk menerbitkan pekerjaan baru.' }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Footer / Pagination -->
        <div class="p-4 border-t border-slate-200 bg-slate-50/30 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="text-[10px] text-slate-500 leading-tight">
                Menampilkan <strong class="text-slate-700">{{ $work_orders->count() }}</strong> dari <strong class="text-slate-700">{{ $work_orders->total() }}</strong> WO APKUK.
            </div>
            @if($work_orders->hasPages())
                <div class="flex items-center gap-2 text-xs">
                    @if($work_orders->onFirstPage())
                        <span class="px-3 py-1.5 rounded border border-slate-200 text-slate-400 bg-slate-50">Sebelumnya</span>
                    @else
                        <a href="{{ $work_orders->previousPageUrl() }}" class="px-3 py-1.5 rounded border border-slate-200 text-slate-700 bg-white hover:bg-slate-50">Sebelumnya</a>
                    @endif
                    <span class="text-slate-500">Hal. {{ $work_orders->currentPage() }} / {{ $work_orders->lastPage() }}</span>
                    @if($work_orders->hasMorePages())
                        <a href="{{ $work_orders->nextPageUrl() }}" class="px-3 py-1.5 rounded border border-slate-200 text-slate-700 bg-white hover:bg-slate-50">Berikutnya</a>
                    @else
                        <span class="px-3 py-1.5 rounded border border-slate-200 text-slate-400 bg-slate-50">Berikutnya</span>
                    @endif
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
