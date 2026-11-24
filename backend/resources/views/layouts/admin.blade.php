<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Dashboard') - ULP Sribhawono</title>
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
        /* PLN Admin Workspace V2: struktur dari referensi Info DIL, tidak menyalin visual mentah. */
        .pln-shell { background: #eef3fa; }
        .pln-shell #admin-sidebar, .pln-shell #mobile-sidebar { background: #edf3fc; border-color: #b9cde7; }
        .pln-shell .sidebar-brand { background: linear-gradient(#f7fbff, #dbe8fa); border-color: #bdcfe4; }
        .pln-shell .sidebar-navigation { gap: 0; padding-top: .65rem; }
        .pln-tree-group { margin: 0 0 .4rem; border: 1px solid #b8cbe4; border-radius: 6px; overflow: hidden; background: #f6f9fe; }
        .pln-tree-summary { cursor: pointer; list-style: none; display:flex; align-items:center; gap:.5rem; min-height: 33px; padding: .45rem .6rem; background: linear-gradient(#dce9fa, #c7dbf4); color:#23446f; font-size: 11px; font-weight: 800; letter-spacing:.03em; }
        .pln-tree-summary::-webkit-details-marker, .pln-tree-subsummary::-webkit-details-marker { display: none; }
        .pln-tree-summary:focus-visible, .pln-tree-subsummary:focus-visible { outline: 2px solid #1d4ed8; outline-offset:-2px; }
        .pln-tree-folder { font-size: 12px; line-height:1; color:#3463a4; }
        .pln-tree-arrow { margin-left:auto; transition: transform .15s ease; }
        .pln-tree-group[open] > .pln-tree-summary .pln-tree-arrow { transform: rotate(90deg); }
        .pln-tree-list { padding: .28rem .3rem .4rem; }
        .pln-tree-list .sidebar-link { font-size: 12px; min-height: 33px; border-radius: 3px; padding: .45rem .5rem; gap: .6rem; }
        .pln-tree-list .sidebar-link[aria-current="page"] { background: #d5e5ff; color:#0c4393; box-shadow: inset 3px 0 #1d4ed8; font-weight: 750; }
        .pln-tree-subgroup { margin: .15rem 0; border-left: 1px dotted #a5bad7; }
        .pln-tree-subsummary { display:flex; align-items:center; gap:.5rem; min-height:29px; padding:.3rem .55rem; color:#315780; cursor:pointer; font-size:12px; font-weight:650; }
        .pln-tree-subgroup[open] > .pln-tree-subsummary { background:#e7effb; }
        .pln-tree-sublist { margin-left:.8rem; border-left: 1px solid #cad7e9; padding-left: .2rem; }
        html[data-sidebar-collapsed="true"] .pln-tree-summary { justify-content:center; padding:.4rem; }
        html[data-sidebar-collapsed="true"] .pln-tree-list, html[data-sidebar-collapsed="true"] .pln-tree-subgroup { display:none; }
        .pln-workspace-strip { display:flex; gap:0; flex-shrink:0; min-height:35px; align-items:end; padding: 5px 9px 0; background:linear-gradient(#dfebfb,#c4d9f4); border-bottom:1px solid #9ebadb; overflow-x:auto; }
        .pln-workspace-strip a, .pln-workspace-current { white-space:nowrap; display:inline-flex; align-items:center; gap:.4rem; min-height:29px; padding: .35rem .85rem; border:1px solid #a4bbd8; border-bottom:0; border-radius:6px 6px 0 0; font-size:12px; color:#365c8d; background:#d9e7f9; }
        .pln-workspace-strip a:hover { color:#0b448d; background:#ecf4ff; }
        .pln-workspace-current { background:#fff; border-color:#91b4db; color:#153c6f; font-weight:700; }
        .pln-workspace-strip a.pln-workspace-current { background:#fff; border-color:#91b4db; color:#153c6f; font-weight:700; }
        .pln-shell main > header { background:linear-gradient(#fff,#edf4ff); border-bottom-color:#bdcfe5; }
        .pln-shell main > div.flex-1 { background:#f2f6fb; }
        .pln-shell main table thead { background:#dce8f8; color:#1e416c; }
        .pln-shell main table thead th { border-bottom:1px solid #9fbada; }
        .pln-shell main table tbody tr:hover { background:#f0f6ff; }
        .pln-shell main input:focus, .pln-shell main select:focus { outline:2px solid #93baf3; outline-offset: 1px; }
        .pln-workspace-panel { border:1px solid #b8cce7; border-radius:6px; background:#fff; box-shadow:0 1px 2px #c3cfdd44; overflow:hidden; }
        .pln-panel-title { background:linear-gradient(#dceafa,#c7dcf4); border-bottom:1px solid #a4bfdf; color:#214c82; font-size:12px; font-weight:800; padding:8px 12px; }
        .pln-panel-body { padding:14px; }
        .pln-filter-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px; }
        .pln-field-label { display:block; color:#29466b; font-size:12px; font-weight:600; margin-bottom:5px; }
        .pln-field { display:block; border:1px solid #adc2db; background:#fff; border-radius:4px; width:100%; min-height:34px; padding:7px 10px; color:#1d3556; font-size:13px; }
        .pln-action-row { display:flex; gap:8px; align-items:center; padding: 12px 14px; background:#eff4fb; border-top:1px solid #c4d3e7; }
        .pln-btn { display:inline-flex; justify-content:center; align-items:center; min-width:90px; min-height:32px; border:1px solid #698fbd; border-radius:4px; padding:6px 14px; font-size:12px; font-weight:700; background:linear-gradient(#fefefe,#cdddf0); color:#1e4b82; }
        .pln-btn:hover { background:#dceafd; }
        .pln-btn-primary { color:#fff; border-color:#154e9d; background:linear-gradient(#3f83df,#1c5ebc); }
        .pln-btn-primary:hover { background:#174fa0; }
        .pln-btn:focus-visible { outline:3px solid #92b8ef; outline-offset:2px; }
        .pln-subtabs { display:flex; flex-wrap:wrap; gap:4px; padding:8px 8px 0; border-bottom:1px solid #a3bfdf; background:#e3edfa; }
        .pln-subtab { cursor:pointer; padding:9px 14px; border:1px solid #adc5e2; border-bottom:0; border-radius:5px 5px 0 0; color:#345a86; font-size:12px; font-weight:650; background:linear-gradient(#eef5ff,#d0e2f8); }
        .pln-subtab[aria-selected="true"] { background:white; color:#123f7a; font-weight:800; position:relative; top:1px; }
        .pln-subtab:focus-visible { outline:2px solid #2361bb; outline-offset:-3px; }
        .pln-tabpanel[hidden] { display:none!important; }
        .pln-detail-dl { display:grid; grid-template-columns: minmax(135px,34%) minmax(0,1fr); gap:0; max-width:960px; }
        .pln-detail-dl dt,.pln-detail-dl dd { border-bottom:1px solid #e1e9f4; padding:9px 10px; min-width:0; font-size:12px; }
        .pln-detail-dl dt { font-weight:650; color:#51647c; background:#f7f9fc; }
        .pln-detail-dl dd { color:#1b3656; overflow-wrap:anywhere; }
        @media(max-width:767px) { .pln-workspace-strip { padding-left:2px; } .pln-workspace-strip a,.pln-workspace-current { padding:.3rem .55rem; font-size:11px; } .pln-detail-dl { grid-template-columns: minmax(115px, 44%) minmax(0, 1fr); } }
        @media (max-width:1023px) {
            html[data-sidebar-collapsed="true"] #mobile-sidebar .sidebar-label { display:inline; }
            html[data-sidebar-collapsed="true"] #mobile-sidebar .pln-tree-list,
            html[data-sidebar-collapsed="true"] #mobile-sidebar .pln-tree-subgroup { display:block; }
        }
        @media (max-width:1023px) {
            html[data-sidebar-collapsed="true"] #mobile-sidebar .pln-tree-summary { justify-content:flex-start; padding:.45rem .6rem; }
        }
    </style>
</head>
<body class="pln-shell flex h-screen overflow-hidden bg-slate-50 text-slate-800 antialiased">
    @php
        $navigationSections = \App\Support\AdminNavigation::sections();
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

        <nav class="sidebar-navigation flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-3" aria-label="Navigasi utama">
            @include('layouts.partials.workspace-navigation', ['mobile' => false])
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
        <nav class="flex-1 overflow-y-auto p-3" aria-label="Navigasi utama seluler">
            @include('layouts.partials.workspace-navigation', ['mobile' => true])
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

        @php
            $workspaceQuickTabs = [
                ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'active' => ['admin.dashboard']],
                ['label' => 'INSPEKSI', 'route' => 'admin.inspeksi.index', 'active' => ['admin.inspeksi.*']],
                ['label' => 'Data Aset', 'route' => 'admin.aset.index', 'active' => ['admin.aset.*']],
                ['label' => 'ROW', 'route' => 'admin.row.index', 'active' => ['admin.row.*']],
            ];
            $workspaceIsQuickPage = request()->routeIs('admin.dashboard', 'admin.inspeksi.*', 'admin.aset.*', 'admin.row.*');
        @endphp
        <div class="pln-workspace-strip" aria-label="Tab modul cepat">
            @foreach ($workspaceQuickTabs as $workspaceTab)
                @php($workspaceTabActive = request()->routeIs(...$workspaceTab['active']))
                <a href="{{ route($workspaceTab['route']) }}" @if ($workspaceTabActive) class="pln-workspace-current" aria-current="page" @endif>{{ $workspaceTab['label'] }}</a>
            @endforeach
            @unless ($workspaceIsQuickPage)
                <span class="pln-workspace-current">@yield('title', 'Dashboard')</span>
            @endunless
        </div>

        <div id="page-loading" class="hidden h-0.5 w-full shrink-0 overflow-hidden bg-blue-100" role="status" aria-live="polite">
            <div class="h-full w-1/3 animate-pulse bg-blue-600"></div>
            <span class="sr-only">Memuat data…</span>
        </div>

        <div class="flex-1 overflow-y-auto p-3 sm:p-4 lg:p-5">
            <x-admin.flash />
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

            // Loading state untuk filter (GET) dan pagination di halaman modul.
            const pageLoading = document.getElementById('page-loading');
            const showLoading = () => pageLoading?.classList.remove('hidden');
            document.querySelectorAll('form[data-loading-form]').forEach((form) => {
                form.addEventListener('submit', () => {
                    form.setAttribute('aria-busy', 'true');
                    form.querySelectorAll('[data-loading-label]').forEach((button) => {
                        button.dataset.originalLabel ??= button.textContent;
                        button.textContent = button.dataset.loadingLabel;
                        button.disabled = true;
                    });
                    showLoading();
                });
            });
            document.querySelectorAll('a[data-loading-link]').forEach((link) => link.addEventListener('click', showLoading));

            // Tindakan tulis: konfirmasi untuk tindakan sensitif dan cegah kirim ganda.
            document.querySelectorAll('form[method="post" i]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    const message = form.dataset.confirm;
                    if (message && !window.confirm(message)) {
                        event.preventDefault();
                        return;
                    }
                    if (form.dataset.submitting === 'true') {
                        event.preventDefault();
                        return;
                    }
                    form.dataset.submitting = 'true';
                    // Tombol yang di-disable tidak ikut terkirim; salin name/value tombol yang
                    // diklik (mis. decision) agar pilihan pengguna tetap sampai ke server.
                    const submitter = event.submitter;
                    if (submitter?.name) {
                        const chosen = document.createElement('input');
                        chosen.type = 'hidden';
                        chosen.name = submitter.name;
                        chosen.value = submitter.value;
                        form.appendChild(chosen);
                    }
                    form.querySelectorAll('button[type="submit"]').forEach((button) => {
                        button.disabled = true;
                        button.classList.add('opacity-70', 'cursor-wait');
                    });
                    showLoading();
                });
            });
            window.addEventListener('pageshow', (event) => {
                if (!event.persisted) return;
                pageLoading?.classList.add('hidden');
                document.querySelectorAll('[data-loading-label]').forEach((button) => {
                    if (button.dataset.originalLabel) button.textContent = button.dataset.originalLabel;
                    button.disabled = false;
                    button.closest('form')?.removeAttribute('aria-busy');
                });
            });
        });
    </script>
    @yield('scripts')
</body>
</html>
