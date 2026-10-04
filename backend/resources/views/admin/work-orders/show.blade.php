@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
            <nav class="flex text-[10px] text-slate-500 font-medium mb-2">
                <ol class="flex items-center space-x-1">
                    <li>Pekerjaan</li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li><a href="{{ route('admin.work-orders.index') }}" class="hover:text-blue-600">Work Order</a></li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li class="text-blue-600">Detail Work Order</li>
                </ol>
            </nav>
            <div class="flex items-center gap-3 mt-1">
                <h1 class="text-2xl font-bold text-slate-900 leading-tight">{{ $wo->id_wo ?? 'WO-XXXX' }}</h1>
                <span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-1 rounded flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> DIKERJAKAN
                </span>
                <span class="text-xs text-blue-600 bg-blue-50 border border-blue-100 px-2 py-1 rounded">Aset Distribusi Tervalidasi</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.work-orders.index') }}" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Kembali ke Work Order
            </a>
            <button class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm disabled:opacity-50" disabled>
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                Edit Data Administrasi
            </button>
            <button class="bg-red-50 border border-red-100 text-red-600 hover:bg-red-100 px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm disabled:opacity-50" disabled>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Batalkan WO
            </button>
        </div>
    </div>

    <!-- Summary Row -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col justify-center">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">STATUS WO</span>
            <div class="flex items-center gap-1.5 font-bold text-blue-700 text-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> DIKERJAKAN
            </div>
            <div class="text-[10px] text-slate-500 mt-1">Penugasan Berjalan</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col justify-center">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">POSKO PELAKSANA</span>
            <div class="font-bold text-slate-800 text-sm">POSKO Sribhawono</div>
            <div class="text-[10px] text-slate-500 mt-1">Shift-Based (Non Harsus)</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col justify-center">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">SHIFT KERJA</span>
            <div class="font-bold text-slate-800 text-sm">Shift Sore</div>
            <div class="text-[10px] text-slate-500 mt-1">16:00 - 00:00 WIB</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col justify-center">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">JENIS PEKERJAAN</span>
            <div class="font-bold text-slate-800 text-sm">PEMASANGAN_APKUK</div>
            <div class="text-[10px] text-slate-500 mt-1">Standardisasi Distribusi</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col justify-center">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">TARGET JADWAL</span>
            <div class="font-bold text-slate-800 text-sm">24 Okt 2024</div>
            <div class="text-[10px] text-slate-500 mt-1">Target Selesai Shift Ini</div>
        </div>
    </div>

    <!-- Main Content Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Details -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Informasi Work Order -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <h3 class="font-bold text-sm text-slate-800">Informasi Work Order</h3>
                </div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">WO ID</div>
                        <div class="text-sm font-semibold text-slate-800">{{ $wo->id_wo ?? 'WO-XXXX' }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">JENIS PEKERJAAN</div>
                        <div class="text-sm font-semibold text-slate-800">PEMASANGAN_APKUK</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">POSKO PELAKSANA</div>
                        <div class="text-sm text-slate-700">Sribhawono</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">SHIFT KERJA</div>
                        <div class="text-sm text-slate-700">Shift Sore (16:00 - 00:00 WIB)</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">ASET / LOKASI DISTRIBUSI</div>
                        <div class="text-sm font-medium text-slate-800">Aset tervalidasi</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">PENYULANG (FEEDER)</div>
                        <div class="text-sm text-slate-700">Penyulang terkait</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">SEKSI JARINGAN</div>
                        <div class="text-sm text-slate-700">Section tervalidasi</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">TITIK KOORDINAT LOKASI</div>
                        <div class="text-xs text-blue-600 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 inline-block">Koordinat tersedia (Data Demo)</div>
                    </div>
                    
                    <div class="col-span-1 md:col-span-2 pt-2 border-t border-slate-100 mt-2">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">DESKRIPSI PEKERJAAN</div>
                        <p class="text-sm text-slate-700 leading-relaxed">Pekerjaan pemasangan dan perapihan perangkat APKUK pada aset distribusi tervalidasi sesuai penugasan preventif shift POSKO. (Data Demonstrasi Tata Letak)</p>
                    </div>

                    <div class="col-span-1 md:col-span-2 bg-amber-50 rounded-lg p-3 border border-amber-100 flex gap-3 mt-2">
                        <svg class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div>
                            <div class="text-xs font-bold text-amber-800">Catatan / Rekomendasi</div>
                            <p class="text-xs text-amber-700 mt-0.5">Pastikan penggunaan APD standar dan koordinasi pengamanan sebelum eksekusi pekerjaan pada aset distribusi.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Informasi APKUK -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Informasi APKUK</h3>
                    </div>
                    <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded border border-blue-100">Modul Pemasangan</span>
                </div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">STATUS APKUK PADA ASET</div>
                        <div class="text-sm font-semibold text-amber-700 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Belum Terpasang / Perlu Pemasangan Baru</div>
                        <div class="text-xs text-slate-500 mt-1">Verifikasi inspeksi visual aset terdahulu.</div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">KEBUTUHAN PEMASANGAN</div>
                        <div class="text-sm font-semibold text-slate-800">1 Set APKUK</div>
                        <div class="text-xs text-slate-500 mt-1">Perangkat APKUK standar operasional.</div>
                    </div>
                    
                    <div class="col-span-1 md:col-span-2 pt-2 border-t border-slate-100 mt-1">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">SPESIFIKASI JENIS PERANGKAT</div>
                        <div class="text-sm font-medium text-slate-800">Perangkat APKUK tervalidasi (Data Demonstrasi)</div>
                    </div>
                    
                    <div class="col-span-1 md:col-span-2">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">CATATAN TEKNIS PETUGAS</div>
                        <p class="text-xs text-slate-600 bg-slate-50 p-3 rounded-lg border border-slate-100">Pemasangan perangkat APKUK pada posisi konstruksi tervalidasi, verifikasi koneksi fisik, dan pastikan pengujian pentanahan pembumian memenuhi standar teknis yang berlaku.</p>
                    </div>
                </div>
            </div>

            <!-- Informasi Carryover (Conditional if has carryover) -->
            @if(isset($wo) && $wo->is_carryover)
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-amber-50/50 px-4 py-3 border-b border-amber-100 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Informasi Carryover Shift</h3>
                    </div>
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded border border-amber-200">KONDISIONAL: RIWAYAT CARRYOVER SHIFT</span>
                </div>
                <div class="p-4">
                    <div class="flex gap-3 mb-4 bg-blue-50/50 p-3 rounded-lg border border-blue-100">
                        <svg class="w-4 h-4 text-blue-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p class="text-xs text-slate-700 leading-relaxed">
                            Work Order ini berstatus aktif <strong>DIKERJAKAN</strong> dengan riwayat konteks penjadwalan <strong>CARRYOVER</strong> dari shift penugasan sebelumnya. Sesuai aturan operasional ULP Sribhawono, pekerjaan carryover menyerap kuota shift berjalan secara prioritas.
                        </p>
                    </div>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                        <div class="bg-slate-50 p-2.5 rounded border border-slate-100">
                            <div class="text-[9px] font-bold text-slate-500 uppercase">SHIFT AWAL</div>
                            <div class="text-xs font-semibold text-slate-800 mt-0.5">Pagi (08:00 - 16:00)</div>
                        </div>
                        <div class="bg-blue-50 p-2.5 rounded border border-blue-100">
                            <div class="text-[9px] font-bold text-blue-600 uppercase">SHIFT TUJUAN</div>
                            <div class="text-xs font-bold text-blue-800 mt-0.5">Sore (16:00 - 00:00)</div>
                        </div>
                        <div class="bg-slate-50 p-2.5 rounded border border-slate-100">
                            <div class="text-[9px] font-bold text-slate-500 uppercase">WAKTU CARRYOVER</div>
                            <div class="text-xs text-slate-700 mt-0.5">24 Okt 2024, 16:00 WIB</div>
                        </div>
                        <div class="bg-slate-50 p-2.5 rounded border border-slate-100">
                            <div class="text-[9px] font-bold text-slate-500 uppercase">PRIORITAS KUOTA</div>
                            <div class="text-xs font-semibold text-slate-700 mt-0.5">Menyerap Kuota Shift</div>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-3">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">ALASAN BELUM SELESAI DI SHIFT SEBELUMNYA:</div>
                        <p class="text-sm font-medium text-slate-800">"Kendala akses jalan lintasan saat terjadi hujan lebat di akhir shift pagi, demi keselamatan kerja petugas penugasan dialihkan ke shift sore."</p>
                        <p class="text-[10px] text-slate-500 mt-2">*Aturan Kuota: Menyerap kuota target 6 WO pada shift tujuan terlebih dahulu sebelum penugasan WO preventif baru.</p>
                    </div>
                </div>
            </div>
            @endif

            <!-- Map Placeholder -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-[350px]">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex justify-between items-center shrink-0">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Titik Lokasi Pekerjaan (Demo)</h3>
                    </div>
                    <button class="text-xs font-semibold text-blue-600 flex items-center gap-1 hover:text-blue-800 disabled:opacity-50" disabled>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg> Buka di Peta Jaringan
                    </button>
                </div>
                <div class="flex-1 bg-slate-100 flex items-center justify-center relative overflow-hidden">
                    <!-- Fake map grid -->
                    <div class="absolute inset-0" style="background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 20px 20px;"></div>
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-20">
                        <div class="w-64 h-64 border-2 border-blue-400 rounded-full"></div>
                    </div>
                    
                    <div class="relative z-10 flex flex-col items-center">
                        <div class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center shadow-lg border-2 border-white mb-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        </div>
                        <div class="bg-slate-900/80 backdrop-blur text-white text-[10px] font-bold px-3 py-1.5 rounded shadow-sm">
                            Aset tervalidasi • Pemasangan APKUK
                        </div>
                    </div>
                    
                    <!-- Empty State for missing coordinate -->
                    <!-- 
                    <div class="relative z-10 flex flex-col items-center bg-white/90 p-4 rounded-xl shadow-sm text-center max-w-sm">
                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mb-3">
                            <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                        <p class="text-sm font-medium text-slate-700">Belum ada titik koordinat tervalidasi yang dapat ditampilkan.</p>
                    </div>
                    -->
                </div>
                <div class="px-4 py-2 bg-slate-50 border-t border-slate-200 flex justify-between items-center text-[10px] text-slate-500">
                    <div>Koordinat GPS: <span class="font-bold text-slate-700">Koordinat tersedia (Data Demonstrasi)</span></div>
                    <div>Peta Netral Vendor: OpenStreetMap / Demo</div>
                </div>
            </div>

            <!-- Dokumentasi -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Dokumentasi Lapangan</h3>
                    </div>
                    <div class="flex gap-1 bg-slate-200/50 p-1 rounded-lg shrink-0">
                        <button class="px-3 py-1 text-[10px] font-bold rounded-md bg-blue-700 text-white shadow-sm disabled:opacity-50" disabled>SEMUA (4)</button>
                        <button class="px-3 py-1 text-[10px] font-bold rounded-md text-slate-600 hover:bg-slate-200 disabled:opacity-50" disabled>BEFORE (1)</button>
                        <button class="px-3 py-1 text-[10px] font-bold rounded-md text-slate-600 hover:bg-slate-200 disabled:opacity-50" disabled>AFTER (0)</button>
                        <button class="px-3 py-1 text-[10px] font-bold rounded-md text-slate-600 hover:bg-slate-200 disabled:opacity-50" disabled>TEMUAN (0)</button>
                        <button class="px-3 py-1 text-[10px] font-bold rounded-md text-slate-600 hover:bg-slate-200 disabled:opacity-50" disabled>SURVEY (1)</button>
                    </div>
                </div>
                
                <!-- Demo empty state logic -->
                <div class="p-4 flex items-center justify-center min-h-[160px] bg-slate-50/50">
                    <div class="text-center">
                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <h4 class="text-sm font-semibold text-slate-700">Belum ada dokumentasi.</h4>
                        <p class="text-xs text-slate-500 mt-1">Foto pekerjaan akan tampil di sini saat domain tersedia.</p>
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- Right Column: Status & Personnel -->
        <div class="space-y-6">
            
            <!-- Status Pekerjaan -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Status Pekerjaan</h3>
                    </div>
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                </div>
                <div class="p-4 space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">STATUS SEKARANG</span>
                        <span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-1 rounded flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> DIKERJAKAN
                        </span>
                    </div>
                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">PELAKSANA</span>
                        <span class="font-semibold text-slate-800 text-sm text-right">POSKO Sribhawono</span>
                    </div>
                    <div class="flex justify-between items-center pb-1">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">SHIFT KERJA</span>
                        <span class="font-semibold text-slate-800 text-sm text-right">Sore (16:00 - 00:00 WIB)</span>
                    </div>
                    
                    <div class="bg-slate-50 p-3 rounded-lg border border-slate-100 space-y-2 mt-4">
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Waktu Dibuat:</span>
                            <span class="font-medium text-slate-800">24 Okt 2024, 16:02 WIB</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Diterima Lapangan:</span>
                            <span class="font-medium text-slate-800">24 Okt 2024, 16:15 WIB</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Mulai Dikerjakan:</span>
                            <span class="font-medium text-blue-700">24 Okt 2024, 16:35 WIB</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Waktu Selesai:</span>
                            <span class="font-medium text-slate-400">— (Sedang Berjalan)</span>
                        </div>
                    </div>
                    
                    <div class="bg-blue-50/50 p-3 rounded-lg border border-blue-100 flex gap-2">
                        <svg class="w-4 h-4 text-blue-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p class="text-[10px] text-blue-800 leading-relaxed">Konfirmasi penyelesaian pekerjaan dilakukan secara eksklusif oleh personel POSKO dari aplikasi lapangan.</p>
                    </div>
                </div>
            </div>

            <!-- Petugas Pelaksana -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <h3 class="font-bold text-sm text-slate-800">Petugas Pelaksana</h3>
                    </div>
                    <span class="text-[10px] font-bold text-slate-500 uppercase">Regu POSKO</span>
                </div>
                <div class="p-4">
                    <!-- Clean empty state as per instruction "If unavailable: show clean empty state." -->
                    <div class="text-center py-6 bg-slate-50 rounded-lg border border-slate-100">
                        <div class="w-10 h-10 bg-slate-200 rounded-full flex items-center justify-center mx-auto mb-2">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        </div>
                        <p class="text-xs font-semibold text-slate-600">Belum ada data personel.</p>
                        <p class="text-[10px] text-slate-500 mt-1 max-w-[200px] mx-auto">Data personel akan dimuat dari Master Petugas saat domain WO tersedia.</p>
                    </div>
                </div>
            </div>

            <!-- Riwayat Status -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50/80 px-4 py-3 border-b border-slate-200 flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <h3 class="font-bold text-sm text-slate-800">Riwayat Status</h3>
                </div>
                <div class="p-4 bg-slate-50/30 border-b border-slate-100">
                    <p class="text-[10px] text-slate-500 leading-relaxed">Siklus hidup Work Order + Audit log lengkap di menu terpisah.</p>
                </div>
                <div class="p-4">
                    <!-- Clean empty state for timeline -->
                    <div class="text-center py-6 bg-slate-50 rounded-lg border border-slate-100">
                        <p class="text-xs font-semibold text-slate-600">Riwayat belum tersedia.</p>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>
@endsection
