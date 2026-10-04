@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto space-y-6 pb-12">
    @if(isset($not_found) && $not_found)
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mb-6 border border-slate-200">
                <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h2 class="text-2xl font-bold text-slate-800 mb-2">P0 tidak ditemukan.</h2>
            <p class="text-slate-500 mb-8 max-w-md">Data pekerjaan prioritas yang Anda cari tidak ada di dalam sistem.</p>
            <a href="{{ route('admin.p0.index') }}" class="px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors shadow-sm inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Kembali ke P0
            </a>
        </div>
    @else
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <nav class="flex text-[10px] text-slate-500 font-medium mb-2">
                <ol class="flex items-center space-x-1">
                    <li>Pekerjaan</li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li><a href="{{ route('admin.p0.index') }}" class="hover:text-blue-600 transition-colors">P0</a></li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li class="text-blue-600">Detail P0</li>
                </ol>
            </nav>
            <div class="flex items-center gap-3 mt-1">
                <h1 class="text-3xl font-black text-slate-900 leading-tight">{{ $p0_id }}</h1>
                <div class="px-2 py-1 bg-slate-100 text-slate-600 border border-slate-200 rounded text-xs font-bold uppercase">{{ $status }}</div>
            </div>
            <p class="text-xs text-slate-500 mt-2">Detail pekerjaan prioritas yang ditangani terpusat oleh Tim HARSUS se-ULP Sribhawono.</p>
        </div>
        
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.p0.index') }}" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 flex items-center gap-2 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Kembali ke P0
            </a>
            <button class="px-4 py-2 bg-white border border-slate-200 text-blue-600 rounded-lg text-sm font-semibold hover:bg-blue-50 flex items-center gap-2 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                Edit Data
            </button>
            <button class="px-4 py-2 bg-white border border-red-200 text-red-600 rounded-lg text-sm font-semibold hover:bg-red-50 flex items-center gap-2 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                Batalkan
            </button>
        </div>
    </div>

    <!-- 4 Meta Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-0 bg-white rounded-xl border border-slate-200 shadow-sm divide-y lg:divide-y-0 lg:divide-x divide-slate-200">
        <div class="p-4 flex flex-col justify-center">
            <div class="flex items-center gap-2 mb-1 text-slate-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="text-[10px] font-bold uppercase tracking-wider">STATUS PEKERJAAN</span>
            </div>
            <div class="text-base font-bold text-slate-800 uppercase">{{ $status }}</div>
            <div class="text-xs text-slate-500 mt-0.5">Status sistem saat ini</div>
        </div>
        <div class="p-4 flex flex-col justify-center">
            <div class="flex items-center gap-2 mb-1 text-slate-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                <span class="text-[10px] font-bold uppercase tracking-wider">PELAKSANA UTAMA</span>
            </div>
            <div class="text-base font-bold text-slate-800 uppercase">{{ $pelaksana }}</div>
            <div class="text-xs text-slate-500 mt-0.5">Pemeliharaan Khusus Se-ULP</div>
        </div>
        <div class="p-4 flex flex-col justify-center">
            <div class="flex items-center gap-2 mb-1 text-slate-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path></svg>
                <span class="text-[10px] font-bold uppercase tracking-wider">SUMBER USULAN</span>
            </div>
            <div class="text-base font-bold text-slate-800 uppercase">{{ $sumber }}</div>
            <div class="text-xs text-slate-500 mt-0.5">Referensi sumber pekerjaan</div>
        </div>
        <div class="p-4 flex flex-col justify-center bg-amber-50/30">
            <div class="flex items-center gap-2 mb-1 text-amber-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <span class="text-[10px] font-bold uppercase tracking-wider">TARGET / JADWAL</span>
            </div>
            <div class="text-base font-bold text-amber-700 uppercase">{{ $target_date }}</div>
            <div class="text-xs text-amber-600 mt-0.5">Jadwal target pengerjaan</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- MAIN CONTENT (Left Column) -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Informasi Pekerjaan -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <h2 class="text-base font-bold text-slate-800">Informasi Pekerjaan</h2>
                    </div>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-8">
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">ID PEKERJAAN</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $p0_id }}</div>
                    </div>
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">SUMBER USULAN</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $sumber }}</div>
                    </div>
                    
                    <div class="md:col-span-2">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">DESKRIPSI PEKERJAAN</div>
                        <div class="text-sm text-slate-700 bg-slate-50 p-3 rounded border border-slate-100">{{ $deskripsi }}</div>
                    </div>
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">ASET / LOKASI GARDU</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $aset }}</div>
                    </div>
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">PENYULANG & SEKSI</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $penyulang }}</div>
                    </div>
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">KONTEKS POSKO (WILAYAH)</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $posko }}</div>
                    </div>
                    
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">TITIK KOORDINAT LAPANGAN</div>
                        <div class="text-sm font-semibold text-slate-800 font-mono">{{ $koordinat ?? 'Belum ada koordinat' }}</div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">KEBUTUHAN PEMADAMAN</div>
                        <div class="text-sm text-slate-700 flex items-center gap-2">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            {{ $pemadaman }}
                        </div>
                    </div>
                    
                    <div class="md:col-span-2 border-t border-slate-100 pt-4">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">KEBUTUHAN MATERIAL & KOMPONEN</div>
                        <div class="text-sm text-slate-700">{{ $material }}</div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="bg-blue-50/50 border border-blue-100 rounded-lg p-3 flex gap-3 items-start">
                            <svg class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.956 11.956 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                            <div>
                                <div class="text-xs font-bold text-blue-800 mb-1">CATATAN / REKOMENDASI</div>
                                <p class="text-xs text-blue-700">{{ $k3 }}</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Lokasi Pekerjaan (MAP) -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <h2 class="text-base font-bold text-slate-800">Lokasi Pekerjaan</h2>
                    </div>
                </div>
                <div class="p-4">
                    <div class="relative w-full h-[300px] bg-slate-100 rounded-lg border border-slate-300 overflow-hidden">
                        <div id="p0-detail-map" class="w-full h-full z-0"></div>
                        @if(!$koordinat)
                            <div class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-slate-50/80 backdrop-blur-sm text-slate-500">
                                <div class="bg-white px-6 py-4 rounded-xl shadow-sm border border-slate-200 flex flex-col items-center">
                                    <svg class="w-8 h-8 mb-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path></svg>
                                    <p class="text-sm font-medium text-slate-600">Belum ada titik koordinat yang didaftarkan.</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Dokumentasi Lapangan -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <h2 class="text-base font-bold text-slate-800">Dokumentasi Lapangan</h2>
                    </div>
                    <div class="flex bg-white rounded-lg border border-slate-200 overflow-hidden text-xs font-semibold">
                        <button class="px-3 py-1.5 bg-slate-100 text-slate-700">Semua (0)</button>
                        <button class="px-3 py-1.5 text-slate-500 hover:bg-slate-50 border-l border-slate-200">Before (0)</button>
                        <button class="px-3 py-1.5 text-slate-500 hover:bg-slate-50 border-l border-slate-200">After (0)</button>
                        <button class="px-3 py-1.5 text-slate-500 hover:bg-slate-50 border-l border-slate-200">Temuan (0)</button>
                        <button class="px-3 py-1.5 text-slate-500 hover:bg-slate-50 border-l border-slate-200">Survey (0)</button>
                    </div>
                </div>
                <div class="p-10 flex flex-col items-center justify-center text-slate-400">
                    <svg class="w-12 h-12 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <p class="text-sm font-medium">Belum ada dokumentasi lapangan yang diunggah.</p>
                </div>
            </div>

        </div>

        <!-- RIGHT SIDEBAR (Right Column) -->
        <div class="space-y-6">
            
            <!-- Reschedule Block (Conditional) -->
            @if($has_reschedule_request)
            <div class="bg-amber-50 rounded-xl border border-amber-200 shadow-sm overflow-hidden">
                <div class="p-4 bg-amber-500 text-white flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <h2 class="text-sm font-bold">Permintaan Reschedule Aktif</h2>
                    </div>
                    <span class="text-[10px] font-bold bg-white text-amber-600 px-2 py-0.5 rounded">PERLU REVIEW</span>
                </div>
                <div class="p-4 text-sm text-slate-700">
                    <p class="mb-3">Terdapat pengajuan penjadwalan ulang dari tim pelaksana.</p>
                    <div class="bg-white border border-amber-200 p-3 rounded mb-3">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">ALASAN RESCHEDULE</div>
                        <p class="font-medium text-slate-800">{{ $reschedule_reason }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div>
                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">TARGET BARU</div>
                            <p class="font-bold text-amber-700">{{ $reschedule_target }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-semibold text-xs hover:bg-blue-700 transition-colors">Setujui</button>
                        <button class="flex-1 bg-white border border-red-200 text-red-600 py-2 rounded-lg font-semibold text-xs hover:bg-red-50 transition-colors">Tolak</button>
                    </div>
                </div>
            </div>
            @endif

            <!-- Status & Pelaksana -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        <h2 class="text-base font-bold text-slate-800">Status & Pelaksana</h2>
                    </div>
                </div>
                <div class="p-4 space-y-4">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Unit Pelaksana</span>
                        <span class="font-bold text-slate-800">{{ $pelaksana }}</span>
                    </div>
                    
                    <div class="border-t border-slate-100 my-2"></div>
                    
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Waktu Dibuat</span>
                        <span class="font-medium text-slate-800">{{ $waktu_dibuat }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Diterima Lapangan</span>
                        <span class="font-medium text-slate-800">{{ $waktu_diterima }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Mulai Dikerjakan</span>
                        <span class="font-medium text-slate-800">{{ $waktu_dikerjakan }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Waktu Selesai</span>
                        <span class="font-medium text-slate-800">{{ $waktu_selesai }}</span>
                    </div>
                    
                    <div class="border-t border-slate-100 my-2"></div>

                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">PETUGAS LAPANGAN</div>
                        <div class="bg-slate-50 border border-slate-200 rounded p-3 text-xs text-slate-600 flex gap-2 items-start">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <p>{{ $petugas }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Status -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <h2 class="text-base font-bold text-slate-800">Riwayat Status</h2>
                    </div>
                </div>
                <div class="p-5">
                    @if(count($history) > 0)
                        <!-- History rendering here when DB exists -->
                    @else
                        <div class="text-center py-6 text-slate-400 text-sm">
                            Belum ada riwayat aktivitas.
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
    @endif
</div>
@endsection

@section('scripts')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if(document.getElementById('p0-detail-map')) {
            var map = L.map('p0-detail-map').setView([-5.3, 105.7], 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
        }
    });
</script>
@endsection
