@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <nav class="flex text-[10px] text-slate-500 font-medium mb-2">
                <ol class="flex items-center space-x-1">
                    <li>Pekerjaan</li>
                    <li><svg class="w-3 h-3 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li class="text-blue-600">P0 (Pekerjaan Prioritas)</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-bold text-slate-900 leading-tight">P0</h1>
            <p class="text-xs text-slate-500 mt-1">Pemantauan P0 POSKO yang terhubung PASILA pada Section yang sama.</p>
        </div>
        <button class="bg-blue-600/50 text-white px-4 py-2 rounded-lg text-sm font-semibold flex items-center gap-2 shadow-sm cursor-not-allowed opacity-75" disabled title="Fitur pembuatan P0 belum diimplementasikan">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Buat P0
        </button>
    </div>

    <!-- 4 KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col relative overflow-hidden">
            <div class="flex justify-between items-start mb-2">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">PO AKTIF</span>
                <div class="p-1.5 bg-blue-50 text-blue-600 rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
            </div>
            <div class="text-3xl font-black text-slate-800">{{ $p0_aktif }}</div>
            <div class="mt-3 flex justify-between items-center text-[10px] text-slate-500 border-t border-slate-100 pt-2">
                <span>Total pekerjaan prio...</span>
                <span class="text-blue-600 font-bold">Prioritas Utama</span>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col relative overflow-hidden">
            <div class="flex justify-between items-start mb-2">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">BARU</span>
                <div class="p-1.5 bg-amber-50 text-amber-600 rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                </div>
            </div>
            <div class="text-3xl font-black text-slate-800">{{ $p0_baru }}</div>
            <div class="mt-3 flex justify-between items-center text-[10px] text-slate-500 border-t border-slate-100 pt-2">
                <span>Telah dibuat, menu...</span>
                <span class="text-amber-600 font-bold">Menunggu ACC / POSKO</span>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col relative overflow-hidden">
            <div class="flex justify-between items-start mb-2">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">SEDANG DIKERJAKAN</span>
                <div class="p-1.5 bg-indigo-50 text-indigo-600 rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
            </div>
            <div class="text-3xl font-black text-slate-800">{{ $p0_dikerjakan }}</div>
            <div class="mt-3 flex justify-between items-center text-[10px] text-slate-500 border-t border-slate-100 pt-2">
                <span>Tim POSKO di lapangan</span>
                <span class="text-indigo-600 font-bold">Proses Tim</span>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col relative overflow-hidden">
            <div class="flex justify-between items-start mb-2">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">SELESAI HARI INI</span>
                <div class="p-1.5 bg-emerald-50 text-emerald-600 rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="text-3xl font-black text-slate-800">{{ $p0_selesai }}</div>
            <div class="mt-3 flex justify-between items-center text-[10px] text-slate-500 border-t border-slate-100 pt-2">
                <span>Pekerjaan tuntas & dive...</span>
                <span class="text-emerald-600 font-bold">Tuntas</span>
            </div>
        </div>
    </div>

    <!-- Info Banner -->
    <div class="bg-blue-50 rounded-xl border border-blue-100 p-4 flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between shadow-sm">
        <div class="flex gap-3">
            <div class="mt-0.5 text-blue-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <p class="text-xs text-blue-800 leading-relaxed max-w-4xl">
                <strong class="font-semibold">Aturan P0 POSKO:</strong> P0 wajib terkait PASILA pada Section/Posko yang sama. Usulan otomatis membutuhkan ACC Admin; bila ditolak Admin membuat P0 pengganti manual.
            </p>
        </div>
        <div class="flex items-center gap-2 bg-white px-3 py-1.5 rounded-lg border border-blue-100 text-xs font-bold text-slate-600 shadow-sm shrink-0">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            P0 TERKAIT SHIFT PASILA
        </div>
    </div>

    <!-- Main List Container -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col">
        <!-- Filter Bar -->
        <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex flex-col lg:flex-row gap-4 items-center justify-between">
            <div class="flex-1 w-full lg:w-auto relative">
                <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" placeholder="Cari ID P0, aset, penyulang, atau lokasi..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:opacity-50" disabled>
            </div>
            
            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                <select class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500 disabled:opacity-50" disabled>
                    <option>Semua Status</option>
                </select>
                <select class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500 disabled:opacity-50" disabled>
                    <option>Semua Sumber</option>
                </select>
                <select class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500 disabled:opacity-50" disabled>
                    <option>Semua Periode</option>
                </select>
                <select class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:border-blue-500 disabled:opacity-50" disabled>
                    <option>Semua Wilayah Posko</option>
                </select>
                
                <button class="text-sm font-semibold text-blue-600 hover:text-blue-800 flex items-center gap-1.5 px-2 disabled:opacity-50" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Reset
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600 min-w-[1000px]">
                <thead class="bg-white text-[10px] uppercase font-bold text-slate-500 border-b border-slate-200 tracking-wider">
                    <tr>
                        <th class="px-6 py-4">PO</th>
                        <th class="px-6 py-4">SUMBER</th>
                        <th class="px-6 py-4">ASET / LOKASI</th>
                        <th class="px-6 py-4">PENYULANG</th>
                        <th class="px-6 py-4">KONTEKS POSKO</th>
                        <th class="px-6 py-4">STATUS</th>
                        <th class="px-6 py-4 text-right">PEMBARUAN TERAKHIR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($p0_list as $p0)
                        <!-- Rendering real data if exists -->
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4 border border-slate-100">
                                        <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                                    </div>
                                    <h3 class="text-base font-bold text-slate-700 mb-1">Belum ada P0.</h3>
                                    <p class="text-xs text-slate-500 max-w-sm">Data pekerjaan prioritas belum tersedia di sistem atau tidak ada data yang cocok dengan filter yang diterapkan.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Footer / Pagination -->
        <div class="p-4 border-t border-slate-200 bg-slate-50/30 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                <div class="text-[10px] text-slate-500 leading-tight">
                    Menampilkan <strong class="text-slate-700">0</strong> dari <strong class="text-slate-700">0</strong> data pekerjaan prioritas aktif ULP Sribhawono.
                </div>
            </div>
            
            <!-- Empty pagination controls -->
            <div class="flex items-center gap-1">
                <button class="w-8 h-8 flex items-center justify-center rounded border border-slate-200 text-slate-400 bg-slate-50 cursor-not-allowed" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </button>
                <button class="w-8 h-8 flex items-center justify-center rounded border border-blue-500 text-white bg-blue-600 font-medium text-xs">1</button>
                <button class="w-8 h-8 flex items-center justify-center rounded border border-slate-200 text-slate-400 bg-slate-50 cursor-not-allowed" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
