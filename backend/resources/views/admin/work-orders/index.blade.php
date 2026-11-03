@extends('layouts.admin')

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
<div class="max-w-7xl mx-auto space-y-6">
    <x-admin.posko-tabs />

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <nav class="flex text-[10px] text-slate-500 font-medium mb-2">
                <ol class="flex items-center space-x-1">
                    <li>Pekerjaan</li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li class="text-blue-600">Work Order</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-bold text-slate-900 leading-tight">Work Order APKUK</h1>
            <p class="text-xs text-slate-500 mt-1">Pemantauan WO PEMASANGAN_APKUK POSKO. Jenis WO HARSUS dan ROW akan memakai model WO yang sama.</p>
        </div>
        <a href="{{ route('admin.work-orders.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold inline-flex items-center gap-2 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Buat WO APKUK
        </a>
    </div>

    <!-- Info Banner -->
    <div class="bg-blue-50 rounded-xl border border-blue-100 p-4 flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between shadow-sm">
        <div class="flex gap-3">
            <div class="mt-0.5 text-blue-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <p class="text-xs text-blue-800 leading-relaxed max-w-4xl">
                <strong class="font-semibold">Shift POSKO Saat Ini:</strong> {{ $shift_name }} ({{ $shift_time }}) • Target preventif {{ $target_per_shift }} WO per shift per Posko (Total referensi kuota shift: {{ $target_total }} WO se-ULP). Satu WO adalah satu pekerjaan tiang; jumlah APKUK fisik dicatat terpisah.
            </p>
        </div>
        <div class="flex items-center gap-2 bg-white px-3 py-1.5 rounded-lg border border-blue-100 text-xs font-bold text-slate-600 shadow-sm shrink-0">
            Shift-Based POSKO
        </div>
    </div>

    <!-- 4 KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">WO SHIFT INI</span>
            <div class="text-3xl font-black text-slate-800">{{ $wo_shift_ini }}</div>
            <div class="mt-3 text-[10px] text-slate-500">Dari referensi target {{ $target_total }} WO</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">SEDANG DIKERJAKAN</span>
            <div class="text-3xl font-black text-slate-800">{{ $wo_dikerjakan }}</div>
            <div class="mt-3 text-[10px] text-slate-500">Shift berjalan</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">SELESAI SHIFT INI</span>
            <div class="text-3xl font-black text-slate-800">{{ $wo_selesai }}</div>
            <div class="mt-3 text-[10px] text-slate-500">Pemasangan dilaporkan selesai</div>
        </div>
        <a href="{{ route('admin.work-orders.index', ['status' => \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE]) }}" class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col hover:border-red-200">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">TERLAMBAT</span>
            <div class="text-3xl font-black {{ $wo_terlambat > 0 ? 'text-red-600' : 'text-slate-800' }}">{{ $wo_terlambat }}</div>
            <div class="mt-3 text-[10px] text-slate-500">WO aktif melewati akhir shift</div>
        </a>
    </div>

    <!-- Posko Progress Section -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="font-bold text-slate-800 text-sm">Progres Beban Kerja per Posko (Shift Berjalan)</h3>
                <p class="text-[10px] text-slate-500 mt-1">Dampak carryover terhadap kuota shift tujuan belum dikunci dalam aturan terbaru.</p>
            </div>
            <div class="text-xs font-semibold text-slate-500">
                Shift {{ $shift_index }} ({{ $shift_name }})
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
            @foreach($poskos as $posko)
            @php
                $progress = $posko_progress[$posko->id];
                $percent = $target_per_shift > 0 ? min(100, (int) round($progress['selesai'] / $target_per_shift * 100)) : 0;
            @endphp
            <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-xs font-bold text-slate-700">{{ $posko->nama_posko }}</span>
                    <span class="text-xs font-bold text-slate-900">{{ $progress['total'] }}/{{ $target_per_shift }}</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-1.5 mb-2 overflow-hidden flex">
                    <div class="bg-emerald-500 h-1.5" style="width: {{ $percent }}%"></div>
                </div>
                <div class="text-[9px] text-slate-500">
                    {{ $progress['selesai'] }} Selesai, {{ $progress['aktif'] }} Aktif
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Main List Container -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col">
        <!-- Filter Bar -->
        <form method="GET" action="{{ route('admin.work-orders.index') }}" class="p-4 border-b border-slate-200 bg-slate-50/50 flex flex-col lg:flex-row gap-4 items-center justify-between">
            <div class="flex-1 w-full lg:w-auto relative">
                <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Cari kode WO atau no. tiang..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>

            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                <select name="status" aria-label="Status" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500">
                    <option value="">Semua Status</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                    <option value="{{ \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE }}" @selected($filters['status'] === \App\Http\Controllers\Admin\WorkOrderController::STATUS_FILTER_OVERDUE)>Terlambat</option>
                </select>
                <select name="posko_id" aria-label="Posko" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500">
                    <option value="">Semua Posko</option>
                    @foreach($poskos as $posko)
                        <option value="{{ $posko->id }}" @selected($filters['posko_id'] === $posko->id)>{{ $posko->nama_posko }}</option>
                    @endforeach
                </select>
                <select name="shift_code" aria-label="Shift" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500">
                    <option value="">Semua Shift</option>
                    @foreach($shift_options as $option)
                        <option value="{{ $option['code'] }}" @selected($filters['shift_code'] === $option['code'])>Shift {{ $option['code'] }} ({{ $option['name'] }})</option>
                    @endforeach
                </select>
                <input type="date" name="work_date" value="{{ $filters['work_date'] }}" aria-label="Tanggal kerja" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500">

                <button type="submit" class="bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Terapkan</button>
                @if($hasFilter)
                    <a href="{{ route('admin.work-orders.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800 px-2">Reset</a>
                @endif
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
                                    <h3 class="text-base font-bold text-slate-700 mb-1">Belum ada Work Order.</h3>
                                    <p class="text-xs text-slate-500 max-w-sm">Belum ada WO APKUK yang tersimpan atau tidak ada data yang cocok dengan filter.</p>
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
    </div>
</div>
@endsection
