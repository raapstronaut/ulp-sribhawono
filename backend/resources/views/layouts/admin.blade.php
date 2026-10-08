<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ULP Sribhawono</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (() => {
            try {
                const savedWidth = Number(localStorage.getItem('adminSidebarWidth'));
                const safeWidth = Number.isFinite(savedWidth) && savedWidth >= 190 && savedWidth <= 300 ? savedWidth : 220;
                document.documentElement.style.setProperty('--admin-sidebar-width', `${safeWidth}px`);

                if (localStorage.getItem('adminSidebarCollapsed') === 'true') {
                    document.documentElement.dataset.sidebarCollapsed = 'true';
                }
            } catch (error) {
                document.documentElement.style.setProperty('--admin-sidebar-width', '220px');
            }
        })();
    </script>
    <style>
        :root { --admin-sidebar-width: 220px; }
        body { font-family: 'Inter', sans-serif; }
        #admin-sidebar { width: var(--admin-sidebar-width); min-width: 190px; max-width: 300px; transition: width 160ms ease, min-width 160ms ease; }
        html[data-sidebar-collapsed="true"] #admin-sidebar { width: 68px; min-width: 68px; max-width: 68px; }
        html[data-sidebar-collapsed="true"] .sidebar-label,
        html[data-sidebar-collapsed="true"] .sidebar-section-label,
        html[data-sidebar-collapsed="true"] .sidebar-footer-label,
        html[data-sidebar-collapsed="true"] .sidebar-brand-content { display: none; }
        html[data-sidebar-collapsed="true"] .sidebar-brand,
        html[data-sidebar-collapsed="true"] .sidebar-link { justify-content: center; }
        html[data-sidebar-collapsed="true"] .sidebar-brand { padding-left: 0.75rem; padding-right: 0.75rem; }
        html[data-sidebar-collapsed="true"] .sidebar-navigation { padding-left: 0.625rem; padding-right: 0.625rem; }
        html[data-sidebar-collapsed="true"] .sidebar-section { margin-top: 0.75rem; border-top: 1px solid rgb(226 232 240); padding-top: 0.75rem; }
        html[data-sidebar-collapsed="true"] #sidebar-resizer { display: none; }
        html[data-sidebar-collapsed="true"] .sidebar-collapse-icon { transform: rotate(180deg); }
        html.sidebar-is-resizing #admin-sidebar { transition: none; }
        #sidebar-resizer::after { content: ''; position: absolute; inset: 0 auto 0 2px; width: 2px; background: transparent; transition: background-color 120ms ease; }
        #sidebar-resizer:hover::after,
        #sidebar-resizer:focus::after,
        html.sidebar-is-resizing #sidebar-resizer::after { background: rgb(59 130 246); }
        @media (max-width: 1023px) {
            #admin-sidebar { display: none; }
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-slate-50 text-slate-800 antialiased">
    @php
        $pendingWorkMenus = [
            ['label' => 'PASILA', 'icon' => 'PS'],
            ['label' => 'Inspeksi', 'icon' => 'IN'],
            ['label' => 'ROW', 'icon' => 'RW'],
        ];
        $pendingControlMenus = [
            ['label' => 'Review & Validasi', 'icon' => 'RV'],
            ['label' => 'Special Case', 'icon' => 'SC'],
            ['label' => 'Peta Jaringan', 'icon' => 'PJ'],
        ];
        $pendingAdminMenus = [
            ['label' => 'Master Data', 'icon' => 'MD'],
            ['label' => 'Analitik', 'icon' => 'AN'],
            ['label' => 'User & Hak Akses', 'icon' => 'UA'],
            ['label' => 'Audit Log', 'icon' => 'AL'],
        ];
    @endphp

    <aside id="admin-sidebar" class="relative hidden h-full flex-shrink-0 flex-col border-r border-slate-200 bg-white lg:flex" aria-label="Sidebar Admin">
        <div class="sidebar-brand flex h-14 shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4">
            <div class="sidebar-brand-content flex min-w-0 items-center gap-2.5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-blue-700 font-bold text-white">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <div class="sidebar-label min-w-0">
                    <h1 class="truncate text-sm font-bold leading-tight text-slate-900">ULP Sribhawono</h1>
                    <p class="truncate text-[10px] text-slate-500">Distribusi Lampung</p>
                </div>
            </div>
            <button id="sidebar-collapse-button" type="button" class="sidebar-label flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-blue-700" aria-label="Ciutkan sidebar" aria-controls="admin-sidebar" aria-expanded="true" title="Ciutkan sidebar">
                <svg class="sidebar-collapse-icon h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>
            <button id="sidebar-expand-button" type="button" class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-blue-700" aria-label="Perbesar sidebar" aria-controls="admin-sidebar" title="Perbesar sidebar">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </button>
        </div>

        <nav class="sidebar-navigation flex-1 space-y-4 overflow-y-auto overflow-x-hidden px-3 py-3" aria-label="Navigasi utama">
            <ul class="space-y-1">
                <li>
                    <a href="{{ route('admin.dashboard') }}" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors {{ request()->routeIs('admin.dashboard') ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50' }}" title="Dashboard" aria-label="Dashboard">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        <span class="sidebar-label truncate">Dashboard</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-section">
                <h2 class="sidebar-section-label mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Pekerjaan</h2>
                <ul class="space-y-1">
                    <li>
                        <a href="{{ route('admin.p0.index') }}" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors {{ request()->routeIs('admin.p0.*') ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50' }}" title="P0" aria-label="P0">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center text-[10px] font-black">P0</span>
                            <span class="sidebar-label truncate">P0</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.work-orders.index') }}" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors {{ request()->routeIs('admin.work-orders.*') ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50' }}" title="Work Order" aria-label="Work Order">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h4"></path></svg>
                            <span class="sidebar-label truncate">Work Order</span>
                        </a>
                    </li>
                    @foreach ($pendingWorkMenus as $pendingMenu)
                        <li>@include('layouts.partials.pending-menu', $pendingMenu)</li>
                    @endforeach
                </ul>
            </div>

            <div class="sidebar-section">
                <h2 class="sidebar-section-label mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Kontrol</h2>
                <ul class="space-y-1">
                    @foreach ($pendingControlMenus as $pendingMenu)
                        <li>@include('layouts.partials.pending-menu', $pendingMenu)</li>
                    @endforeach
                </ul>
            </div>

            <div class="sidebar-section">
                <h2 class="sidebar-section-label mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Data & Admin</h2>
                <ul class="space-y-1">
                    @foreach ($pendingAdminMenus as $pendingMenu)
                        <li>@include('layouts.partials.pending-menu', $pendingMenu)</li>
                    @endforeach
                    <li>
                        <a href="{{ route('admin.account.edit') }}" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors {{ request()->routeIs('admin.account.*') ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50' }}" title="Pengaturan Akun" aria-label="Pengaturan Akun">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            <span class="sidebar-label truncate">Pengaturan Akun</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="shrink-0 border-t border-slate-200 p-3">
            <div class="sidebar-link flex items-center gap-2 rounded-md px-2 text-xs text-slate-500" title="Mode Pengembangan">
                <span class="h-2 w-2 shrink-0 rounded-full bg-amber-400"></span>
                <span class="sidebar-footer-label sidebar-label truncate">Mode Pengembangan</span>
            </div>
        </div>

        <div id="sidebar-resizer" class="absolute -right-1 top-0 z-20 h-full w-2 cursor-col-resize outline-none" role="separator" aria-label="Ubah lebar sidebar" aria-orientation="vertical" aria-valuemin="190" aria-valuemax="300" tabindex="0" title="Geser untuk mengubah lebar sidebar"></div>
    </aside>

    <div id="mobile-sidebar-backdrop" class="fixed inset-0 z-40 hidden bg-slate-950/40 lg:hidden" aria-hidden="true"></div>
    <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-white shadow-2xl transition-transform duration-200 lg:hidden" aria-label="Navigasi seluler" aria-hidden="true">
        <div class="flex h-14 items-center justify-between border-b border-slate-200 px-4">
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-md bg-blue-700 text-white">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <div><div class="text-sm font-bold text-slate-900">ULP Sribhawono</div><div class="text-[10px] text-slate-500">Menu Admin</div></div>
            </div>
            <button id="mobile-sidebar-close" type="button" class="flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100" aria-label="Tutup navigasi">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto p-3">
            <a href="{{ route('admin.dashboard') }}" class="block rounded-md px-3 py-2.5 text-sm font-medium {{ request()->routeIs('admin.dashboard') ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">Dashboard</a>
            <a href="{{ route('admin.p0.index') }}" class="block rounded-md px-3 py-2.5 text-sm font-medium {{ request()->routeIs('admin.p0.*') ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">P0</a>
            <a href="{{ route('admin.work-orders.index') }}" class="block rounded-md px-3 py-2.5 text-sm font-medium {{ request()->routeIs('admin.work-orders.*') ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">Work Order</a>
            <a href="{{ route('admin.account.edit') }}" class="block rounded-md px-3 py-2.5 text-sm font-medium {{ request()->routeIs('admin.account.*') ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">Pengaturan Akun</a>
            <p class="mt-3 border-t border-slate-100 px-3 pt-3 text-xs text-slate-400">Modul lainnya belum tersedia.</p>
        </nav>
    </aside>

    <main class="flex h-full min-w-0 flex-1 flex-col overflow-hidden">
        <header class="z-30 flex min-h-14 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 py-2 sm:px-5">
            <div class="flex min-w-0 items-center gap-3">
                <button id="mobile-sidebar-open" type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-600 lg:hidden" aria-label="Buka navigasi" aria-controls="mobile-sidebar" aria-expanded="false">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-bold leading-tight text-slate-900 sm:text-base">ULP Sribhawono <span class="ml-1 rounded bg-blue-100 px-2 py-0.5 align-middle text-[9px] text-blue-700">DISTRIBUSI</span></h2>
                    <p class="hidden text-[10px] text-slate-500 sm:block">Sistem Pengelolaan Pekerjaan Jaringan Distribusi</p>
                </div>
            </div>

            <div class="flex flex-shrink-0 items-center gap-1 sm:gap-2">
                <div id="clock-display" class="hidden rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-[11px] font-medium text-slate-600 xl:block">Memuat waktu...</div>
                <button type="button" onclick="window.location.reload()" class="hidden rounded-md px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900 md:block" title="Muat ulang halaman">Muat Ulang</button>
                <div class="ml-1 flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) (auth()->user()->username ?: 'ADMIN'), 0, 2)) }}</div>
                    <div class="hidden md:block">
                        <div class="text-xs font-bold text-slate-900">{{ auth()->user()->username ?: 'ADMIN' }}</div>
                        <div class="text-[10px] text-slate-500">ADMIN · ULP Sribhawono</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-red-50 hover:text-red-700" title="Keluar dari Web Admin">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"></path></svg>
                        <span class="hidden sm:inline">Keluar</span>
                    </button>
                </form>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-3 sm:p-4 lg:p-5">
            @yield('content')
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const root = document.documentElement;
            const sidebar = document.getElementById('admin-sidebar');
            const collapseButton = document.getElementById('sidebar-collapse-button');
            const expandButton = document.getElementById('sidebar-expand-button');
            const resizer = document.getElementById('sidebar-resizer');
            const minimumWidth = 190;
            const maximumWidth = 300;
            const defaultWidth = 220;
            let currentWidth = Number.parseInt(getComputedStyle(root).getPropertyValue('--admin-sidebar-width'), 10) || defaultWidth;

            const store = (key, value) => {
                try { localStorage.setItem(key, value); } catch (error) { /* Penyimpanan browser tidak tersedia. */ }
            };

            const setCollapsed = (collapsed) => {
                root.dataset.sidebarCollapsed = collapsed ? 'true' : 'false';
                store('adminSidebarCollapsed', collapsed ? 'true' : 'false');
                collapseButton?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                collapseButton?.setAttribute('aria-label', 'Ciutkan sidebar');
                expandButton?.classList.toggle('hidden', !collapsed);
            };

            setCollapsed(root.dataset.sidebarCollapsed === 'true');
            collapseButton?.addEventListener('click', () => setCollapsed(true));
            expandButton?.addEventListener('click', () => setCollapsed(false));

            const setSidebarWidth = (width) => {
                currentWidth = Math.min(maximumWidth, Math.max(minimumWidth, Math.round(width)));
                root.style.setProperty('--admin-sidebar-width', `${currentWidth}px`);
                store('adminSidebarWidth', String(currentWidth));
                resizer?.setAttribute('aria-valuenow', String(currentWidth));
            };

            setSidebarWidth(currentWidth);

            resizer?.addEventListener('pointerdown', (event) => {
                if (root.dataset.sidebarCollapsed === 'true') return;
                event.preventDefault();
                resizer.setPointerCapture(event.pointerId);
                root.classList.add('sidebar-is-resizing');
            });
            resizer?.addEventListener('pointermove', (event) => {
                if (!resizer.hasPointerCapture(event.pointerId)) return;
                setSidebarWidth(event.clientX - sidebar.getBoundingClientRect().left);
            });
            const stopResize = (event) => {
                if (resizer?.hasPointerCapture(event.pointerId)) resizer.releasePointerCapture(event.pointerId);
                root.classList.remove('sidebar-is-resizing');
            };
            resizer?.addEventListener('pointerup', stopResize);
            resizer?.addEventListener('pointercancel', stopResize);
            resizer?.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home'].includes(event.key)) return;
                event.preventDefault();
                if (event.key === 'Home') setSidebarWidth(defaultWidth);
                if (event.key === 'ArrowLeft') setSidebarWidth(currentWidth - 10);
                if (event.key === 'ArrowRight') setSidebarWidth(currentWidth + 10);
            });

            const mobileSidebar = document.getElementById('mobile-sidebar');
            const mobileBackdrop = document.getElementById('mobile-sidebar-backdrop');
            const mobileOpen = document.getElementById('mobile-sidebar-open');
            const mobileClose = document.getElementById('mobile-sidebar-close');
            const setMobileOpen = (open) => {
                mobileSidebar?.classList.toggle('-translate-x-full', !open);
                mobileBackdrop?.classList.toggle('hidden', !open);
                mobileSidebar?.setAttribute('aria-hidden', open ? 'false' : 'true');
                mobileOpen?.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            mobileOpen?.addEventListener('click', () => setMobileOpen(true));
            mobileClose?.addEventListener('click', () => setMobileOpen(false));
            mobileBackdrop?.addEventListener('click', () => setMobileOpen(false));
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') setMobileOpen(false);
            });

            const updateClock = () => {
                const now = new Date();
                const dateStr = now.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' });
                const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, hourCycle: 'h23', timeZone: 'Asia/Jakarta' });
                const clock = document.getElementById('clock-display');
                if (clock) clock.textContent = `${dateStr} · ${timeStr} WIB`;
            };

            updateClock();
            setInterval(updateClock, 60000);
        });
    </script>
    @yield('scripts')
</body>
</html>
