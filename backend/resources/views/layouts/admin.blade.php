<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ULP Sribhawono</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-slate-200 flex flex-col flex-shrink-0 h-full overflow-y-auto">
        <!-- Logo/Header Sidebar -->
        <div class="h-16 flex items-center px-6 border-b border-slate-200">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-blue-700 rounded flex items-center justify-center text-white font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <div>
                    <h1 class="font-bold text-sm text-slate-900 leading-tight">ULP Sribhawono</h1>
                    <p class="text-[10px] text-slate-500">Distribusi Lampung</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 px-4 py-4 space-y-6">
            
            <!-- Main Nav -->
            <ul class="space-y-1">
                <li>
                    <a href="#" class="flex items-center gap-3 px-3 py-2 bg-blue-600 text-white rounded-md font-medium text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        Dashboard
                    </a>
                </li>
            </ul>

            <!-- PEKERJAAN -->
            <div>
                <h2 class="text-[10px] font-bold text-slate-400 tracking-wider uppercase mb-2 px-3">Pekerjaan</h2>
                <ul class="space-y-1">
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><span class="w-4 text-center font-bold text-xs text-red-500">P0</span> P0</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg> Work Order</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg> PASILA</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg> Inspeksi</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg> ROW</a></li>
                </ul>
            </div>

            <!-- KONTROL -->
            <div>
                <h2 class="text-[10px] font-bold text-slate-400 tracking-wider uppercase mb-2 px-3">Kontrol</h2>
                <ul class="space-y-1">
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Review & Validasi</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg> Special Case</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path></svg> Peta Jaringan</a></li>
                </ul>
            </div>

            <!-- DATA & ADMIN -->
            <div>
                <h2 class="text-[10px] font-bold text-slate-400 tracking-wider uppercase mb-2 px-3">Data & Admin</h2>
                <ul class="space-y-1">
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg> Master Data</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg> Analitik</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> User & Hak Akses</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg> Audit Log</a></li>
                    <li><a href="#" class="flex items-center gap-3 px-3 py-2 text-slate-600 hover:bg-slate-50 rounded-md text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg> Pengaturan</a></li>
                </ul>
            </div>
        </nav>
        
        <div class="p-4 border-t border-slate-200">
            <div class="flex items-center gap-2 text-xs text-slate-500">
                <div class="w-2 h-2 rounded-full bg-amber-400"></div>
                Mode Pengembangan
            </div>
            <div class="text-[10px] text-slate-400 mt-1 pl-4">Dashboard Operasional</div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden">
        <!-- Top Header -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0 z-10">
            <div class="flex items-center gap-6">
                <div>
                    <h2 class="font-bold text-lg text-slate-900 leading-tight">ULP Sribhawono <span class="bg-blue-100 text-blue-700 text-[10px] px-2 py-0.5 rounded ml-2 align-middle">DISTRIBUSI</span></h2>
                    <p class="text-[11px] text-slate-500">Sistem Pengelolaan Pekerjaan Jaringan Distribusi</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <div id="clock-display" class="flex items-center gap-2 text-xs font-medium text-slate-600 bg-slate-50 px-3 py-1.5 rounded-md border border-slate-200">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Memuat waktu...
                </div>
                
                <button onclick="window.location.reload()" class="flex items-center gap-2 text-xs font-medium text-slate-600 hover:text-slate-900 px-2 py-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Muat Ulang
                </button>
                
                <div class="w-px h-6 bg-slate-200 mx-2"></div>
                
                <button class="relative p-2 text-slate-400 hover:text-slate-600 rounded-full hover:bg-slate-50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    <!-- no red dot if 0 notifications, but keeping static for UI -->
                </button>
                
                <div class="flex items-center gap-3 pl-2">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">
                        AD
                    </div>
                    <div class="hidden md:block">
                        <div class="text-xs font-bold text-slate-900">Admin</div>
                        <div class="text-[10px] text-slate-500">ULP Sribhawono</div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Scrollable Content Area -->
        <div class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </div>
    </main>
    
    <script>
        // Initial clock setup
        document.addEventListener('DOMContentLoaded', function() {
            if(typeof updateClock === 'function') updateClock();
        });
    </script>
    @yield('scripts')
</body>
</html>
