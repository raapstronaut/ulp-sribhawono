@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
@php
    $fields = [
        'POSKO' => ['route' => 'admin.work-orders.index', 'description' => 'WO APKUK, PASILA, P0'],
        'ROW' => ['route' => 'admin.row.index', 'description' => 'Rabas, pangkas, tebang'],
        'HARSUS' => ['route' => 'admin.harsus.index', 'description' => 'Pekerjaan khusus'],
        'INSPEKSI' => ['route' => 'admin.inspeksi.index', 'description' => 'Kunjungan dan temuan'],
    ];
    $monitorCards = [
        'active' => [
            'title' => 'Pekerjaan Aktif',
            'count' => $kpis['active'],
            'note' => 'WO POSKO, ROW, HARSUS',
            'tone' => 'text-blue-700',
        ],
        'overdue' => [
            'title' => 'Terlambat',
            'count' => $kpis['terlambat'],
            'note' => 'WO aktif melewati tenggat',
            'tone' => $kpis['terlambat'] > 0 ? 'text-red-600' : 'text-slate-800',
        ],
        'verification' => [
            'title' => 'Perlu Verifikasi',
            'count' => $pending['reports'] + $pending['findings'],
            'note' => 'Laporan WO dan temuan inspeksi',
            'tone' => ($pending['reports'] + $pending['findings']) > 0 ? 'text-amber-600' : 'text-slate-800',
        ],
    ];
    $lastMonth = \Carbon\CarbonImmutable::now(\App\Services\ShiftService::TIMEZONE)->startOfMonth()->subMonth();
    $awardMonths = [];
    for ($i = 0; $i < 12; $i++) {
        $month = $lastMonth->subMonths($i);
        $awardMonths[$month->format('Y-m')] = $month->locale('id')->translatedFormat('F Y');
    }
    $requestedAwardMonth = request()->query('award_month');
    $awardMonth = is_string($requestedAwardMonth) && array_key_exists($requestedAwardMonth, $awardMonths)
        ? $requestedAwardMonth
        : array_key_first($awardMonths);
@endphp

<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header
        section="Ringkasan"
        title="Dashboard Operasional"
        state="live"
        :description="'Diperbarui '.$context['updated_at_label'].' - Shift '.$shiftData['code'].' ('.$shiftData['time'].') - '.$context['active_poskos'].' POSKO aktif - '.$context['active_penyulangs'].' penyulang aktif'"
    />

    {{-- Pemberitahuan rekonsiliasi APKUK --}}
    @if (($apkuk['pending_reports'] ?? 0) > 0)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-amber-900">
                        APKUK menunggu rekonsiliasi
                    </h3>

                    <p class="mt-1 text-xs text-amber-800">
                        Terdapat {{ $apkuk['pending_reports'] }}
                        laporan APKUK yang perlu diperiksa.
                        Laporan pending tidak dihitung sebagai data aktif.
                    </p>
                </div>

                <a
                    href="{{ route('admin.apkuk-reconciliation.index') }}"
                    class="rounded-lg bg-amber-700 px-4 py-2 text-xs font-bold text-white hover:bg-amber-800"
                >
                    Periksa Rekonsiliasi
                </a>
            </div>
        </div>
    @endif

    {{-- AKSES-CEPAT-DASHBOARD: BEGIN --}}
    <section aria-labelledby="quick-access-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="quick-access-heading" class="text-sm font-bold text-slate-900">Akses Cepat</h2>
            <p class="text-xs text-slate-500">Buka modul operasional secara langsung.</p>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <a href="{{ route('admin.maps.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka Peta Jaringan
            </a>
            <a href="{{ route('admin.apkuk.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka APKUK
            </a>
            <a href="{{ route('admin.pasila.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka halaman PASILA
            </a>
            <a href="{{ route('admin.inspeksi.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka Inspeksi
            </a>
            <a href="{{ route('admin.harsus.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka HARSUS
            </a>
            <a href="{{ route('admin.row.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-blue-700 shadow-sm hover:border-blue-300 hover:bg-blue-50">
                Buka ROW
            </a>
        </div>
    </section>
    {{-- AKSES-CEPAT-DASHBOARD: END --}}

    {{-- 1. Monitoring harian --}}
    <section aria-labelledby="monitor-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 id="monitor-heading" class="text-lg font-bold text-slate-900">Monitoring Harian</h2>
                <p class="text-xs text-slate-500">Klik indikator untuk melihat rincian per bidang.</p>
            </div>
            <a href="{{ route('admin.reviews.index') }}" class="text-xs font-semibold text-blue-700 hover:underline">Buka Review &amp; Validasi</a>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ($monitorCards as $key => $card)
                <button
                    type="button"
                    data-dashboard-open="monitor-{{ $key }}"
                    aria-haspopup="dialog"
                    class="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-blue-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $card['title'] }}</span>
                    <div class="mt-2 flex items-end justify-between gap-2">
                        <strong class="text-3xl font-black {{ $card['tone'] }}">{{ number_format($card['count'], 0, ',', '.') }}</strong>
                        <span class="text-xs text-blue-700">Lihat rincian →</span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">{{ $card['note'] }}</p>
                </button>
            @endforeach
        </div>
        <p class="text-xs text-slate-500">
            Angka aktif dan terlambat menghitung Work Order POSKO, ROW, HARSUS. INSPEKSI memiliki kegiatan kunjungan/temuan tersendiri,
            sehingga tidak dimasukkan sebagai WO palsu. Perlu Verifikasi mencakup laporan WO dan temuan INSPEKSI.
        </p>
    </section>

    {{-- 2. Bidang: empat kartu ringkas, rincian hanya saat dipilih --}}
    <section aria-labelledby="teams-heading" class="space-y-3">
        <div>
            <h2 id="teams-heading" class="text-lg font-bold text-slate-900">Ringkasan Per Bidang</h2>
            <p class="text-xs text-slate-500">Pilih bidang untuk membuka popup. Detail penuh tersedia di halaman masing-masing.</p>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($fields as $team => $info)
                <button
                    type="button"
                    data-dashboard-open="field-{{ $team }}"
                    aria-haspopup="dialog"
                    class="flex min-h-28 flex-col items-start justify-between rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-blue-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <div class="flex w-full items-center justify-between gap-2">
                        <strong class="text-sm font-black text-slate-900">{{ $team }}</strong>
                        <span class="text-blue-700" aria-hidden="true">↗</span>
                    </div>
                    <span class="text-xs text-slate-500">{{ $info['description'] }}</span>
                    <span class="text-xs font-semibold text-blue-700">Lihat ringkasan</span>
                </button>
            @endforeach
        </div>
    </section>

    {{-- 3. Analitik: empat grafik dengan satu filter bersama --}}
    <section aria-labelledby="trends-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="trends-heading" class="text-lg font-bold text-slate-900">Analitik &amp; Tren</h2>
                <p class="text-xs text-slate-500">{{ $insights['start_date'] }} s.d. {{ $insights['end_date'] }} · Data operasional aktual</p>
            </div>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                <input type="hidden" name="award_month" value="{{ $awardMonth }}">
                <label for="trend-period" class="text-xs font-semibold text-slate-600">Periode</label>
                <select id="trend-period" name="trend_period" onchange="this.form.requestSubmit()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    @foreach (\App\Services\DashboardInsightsService::PERIODS as $value => $label)
                        <option value="{{ $value }}" @selected($insights['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="rounded-md border px-2 py-1 text-sm">Terapkan</button></noscript>
            </form>
        </div>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($insights['charts'] as $team => $chart)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Grafik {{ $team }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-black text-slate-900">{{ $team }}</h3>
                            <p class="text-xs text-slate-500">{{ $chart['first_label'] }} vs {{ $chart['second_label'] }}</p>
                        </div>
                        <a href="{{ route($fields[$team]['route']) }}" class="text-xs font-semibold text-blue-700 hover:underline">Detail →</a>
                    </div>
                    <div class="mt-4 flex h-32 items-end gap-1.5" role="img" aria-label="{{ $chart['first_label'] }} {{ $chart['total_first'] }}; {{ $chart['second_label'] }} {{ $chart['total_second'] }} selama {{ $insights['period_label'] }}">
                        @foreach ($chart['buckets'] as $bucket)
                            <div class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $bucket['start'] }} - {{ $bucket['end'] }}: {{ $chart['first_label'] }} {{ $bucket['first'] }}, {{ $chart['second_label'] }} {{ $bucket['second'] }}">
                                <div class="flex h-24 w-full items-end justify-center gap-0.5">
                                    <div class="w-1/2 max-w-6 rounded-t bg-blue-600" style="height: {{ $bucket['first'] > 0 ? max(3, round($bucket['first'] / $chart['max'] * 100)) : 0 }}%"></div>
                                    <div class="w-1/2 max-w-6 rounded-t bg-emerald-500" style="height: {{ $bucket['second'] > 0 ? max(3, round($bucket['second'] / $chart['max'] * 100)) : 0 }}%"></div>
                                </div>
                                <span class="w-full truncate text-center text-[9px] text-slate-500">{{ $bucket['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-600">
                        <span><span class="mr-1 inline-block h-2 w-2 rounded-sm bg-blue-600"></span>{{ $chart['first_label'] }}: <strong>{{ number_format($chart['total_first'], 0, ',', '.') }}</strong></span>
                        <span><span class="mr-1 inline-block h-2 w-2 rounded-sm bg-emerald-500"></span>{{ $chart['second_label'] }}: <strong>{{ number_format($chart['total_second'], 0, ',', '.') }}</strong></span>
                    </div>
                    @if ($chart['total_first'] === 0 && $chart['total_second'] === 0)
                        <p class="mt-2 text-xs italic text-slate-400">Belum ada aktivitas tercatat pada periode ini.</p>
                    @endif
                </article>
            @endforeach
        </div>
        <p class="text-xs text-slate-500">
            POSKO/ROW/HARSUS: jumlah WO dibuat dan selesai. INSPEKSI: jumlah kunjungan berdasarkan waktu kunjungan dan temuan berdasarkan waktu pencatatan.
            Kedua ukuran INSPEKSI bukan pasangan pekerjaan masuk/selesai dan tidak boleh dipakai sebagai skor kinerja.
        </p>
    </section>

    {{-- 4. Apresiasi: tempat tampilan saja; tidak ada ranking, input, atau pemenang palsu --}}
    <section aria-labelledby="awards-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="awards-heading" class="text-lg font-bold text-slate-900">Apresiasi Bulanan</h2>
                <p class="text-xs text-slate-500">Konsep tampilan. Parameter penilaian belum ditetapkan.</p>
            </div>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                <input type="hidden" name="trend_period" value="{{ $insights['period'] }}">
                <label for="award-month" class="text-xs font-semibold text-slate-600">Bulan</label>
                <select id="award-month" name="award_month" onchange="this.form.requestSubmit()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    @foreach ($awardMonths as $value => $label)
                        <option value="{{ $value }}" @selected($awardMonth === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="rounded-md border px-2 py-1 text-sm">Terapkan</button></noscript>
            </form>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div role="tablist" aria-label="Bidang apresiasi" class="mb-4 grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1">
                @foreach ($fields as $team => $_info)
                    <button type="button" role="tab" data-award-tab="{{ $team }}" aria-selected="{{ $team === 'POSKO' ? 'true' : 'false' }}" aria-controls="award-panel-{{ $team }}" class="rounded-md px-1 py-2 text-[11px] font-semibold transition sm:text-sm {{ $team === 'POSKO' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-600' }}">
                        {{ $team }}
                    </button>
                @endforeach
            </div>
            @foreach ($fields as $team => $_info)
                <div id="award-panel-{{ $team }}" data-award-panel="{{ $team }}" role="tabpanel" @if ($team !== 'POSKO') hidden @endif>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-center">
                            <span class="text-2xl" aria-hidden="true">🏆</span>
                            <h3 class="mt-2 text-sm font-bold">Tim Terbaik {{ $team }}</h3>
                            <p class="mt-2 text-sm text-slate-500">Belum ditetapkan</p>
                        </div>
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-center">
                            <span class="text-2xl" aria-hidden="true">🏅</span>
                            <h3 class="mt-2 text-sm font-bold">Petugas Terbaik {{ $team }}</h3>
                            <p class="mt-2 text-sm text-slate-500">Belum ditetapkan</p>
                        </div>
                    </div>
                </div>
            @endforeach
            <p class="mt-4 text-xs text-slate-500">
                {{ $awardMonths[$awardMonth] }} · Belum ada mekanisme penentuan, pengisian pemenang, atau ranking otomatis.
            </p>
        </div>
    </section>
</div>

{{-- Modal monitoring: nilai semua bidang; angka INSPEKSI untuk WO sengaja tidak dibuat-buat. --}}
@foreach ($monitorCards as $key => $card)
    <dialog id="monitor-{{ $key }}" class="dashboard-dialog w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl" aria-labelledby="monitor-title-{{ $key }}">
        <div class="max-h-[80vh] overflow-y-auto p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="monitor-title-{{ $key }}" class="text-lg font-bold text-slate-900">{{ $card['title'] }}</h2>
                    <p class="text-xs text-slate-500">Rincian menurut bidang · Data saat ini</p>
                </div>
                <button type="button" data-dashboard-close class="rounded-md border border-slate-200 px-3 py-1 text-sm" aria-label="Tutup popup">Tutup</button>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <label for="monitor-filter-{{ $key }}" class="text-xs font-semibold text-slate-600">Filter bidang</label>
                <select id="monitor-filter-{{ $key }}" data-monitor-filter="{{ $key }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="ALL">Semua bidang</option>
                    @foreach ($fields as $team => $_info)
                        <option value="{{ $team }}">{{ $team }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mt-3 space-y-2">
                @foreach ($fields as $team => $info)
                    @php $value = $insights['monitoring'][$key][$team]; @endphp
                    <div data-monitor-row="{{ $key }}" data-monitor-team="{{ $team }}" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 p-3">
                        <div>
                            <strong class="text-sm">{{ $team }}</strong>
                            @if ($value === null)
                                <p class="text-xs text-slate-500">Dihitung dari kegiatan INSPEKSI tersendiri</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <strong class="text-lg">{{ $value === null ? '—' : number_format($value, 0, ',', '.') }}</strong>
                            <a href="{{ route($info['route']) }}" class="text-xs font-semibold text-blue-700 hover:underline">Buka →</a>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 border-t border-slate-200 pt-4">
                <h3 class="text-sm font-bold text-slate-900">Daftar aktivitas terkait</h3>
                <p class="mt-1 text-xs text-slate-500">Menampilkan maksimal 60 entri. Untuk daftar lengkap gunakan halaman bidang.</p>
                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label class="block text-xs font-semibold text-slate-600">
                        Cari kode / status
                        <input type="search" data-monitor-search="{{ $key }}" placeholder="Cari entri yang tampil..." class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal">
                    </label>
                    <label class="block text-xs font-semibold text-slate-600">
                        Status
                        <select data-monitor-status="{{ $key }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                            <option value="ALL">Semua status</option>
                            @foreach (collect($insights['monitoring_items'][$key])->pluck('status')->unique() as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <ul class="mt-3 space-y-2" aria-label="Daftar terkait {{ $card['title'] }}">
                    @forelse ($insights['monitoring_items'][$key] as $item)
                        <li data-monitor-item="{{ $key }}" data-monitor-team="{{ $item['team'] }}" data-monitor-item-status="{{ $item['status'] }}" data-monitor-searchtext="{{ mb_strtolower($item['title'].' '.$item['status']) }}" class="rounded-lg border border-slate-100 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-800">{{ $item['title'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $item['team'] }} · {{ $item['status'] }}</p>
                                </div>
                                <a href="{{ $item['url'] }}" class="shrink-0 text-xs font-semibold text-blue-700 hover:underline">Buka →</a>
                            </div>
                        </li>
                    @empty
                        <li class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Belum ada aktivitas sesuai indikator.</li>
                    @endforelse
                </ul>
            </div>
            @if ($key === 'verification')
                <a href="{{ route('admin.reviews.index') }}" class="mt-4 inline-block text-sm font-bold text-blue-700 hover:underline">Buka seluruh antrean Review &amp; Validasi →</a>
            @endif
            <p class="mt-3 text-xs text-slate-500">Filter dan pencarian berlaku pada daftar yang ditampilkan, bukan seluruh database.</p>
        </div>
    </dialog>
@endforeach

{{-- Modal bidang --}}
@foreach ($fields as $team => $info)
    <dialog id="field-{{ $team }}" class="dashboard-dialog w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl" aria-labelledby="field-title-{{ $team }}">
        <div class="max-h-[80vh] overflow-y-auto p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="field-title-{{ $team }}" class="text-lg font-bold text-slate-900">{{ $team }}</h2>
                    <p class="text-xs text-slate-500">{{ $info['description'] }}</p>
                </div>
                <button type="button" data-dashboard-close class="rounded-md border border-slate-200 px-3 py-1 text-sm" aria-label="Tutup popup">Tutup</button>
            </div>
            <dl class="mt-4 divide-y divide-slate-100">
                @foreach ($insights['field_details'][$team] as $detail)
                    <div class="flex items-center justify-between gap-3 py-3 text-sm">
                        <dt class="text-slate-600">{{ $detail['label'] }}</dt>
                        <dd class="font-black text-slate-900">{{ number_format($detail['value'], 0, ',', '.') }}</dd>
                    </div>
                @endforeach
            </dl>
            <a href="{{ route($info['route']) }}" class="mt-4 block rounded-lg bg-blue-700 px-4 py-3 text-center text-sm font-bold text-white hover:bg-blue-800">
                Lihat Halaman {{ $team }} →
            </a>
        </div>
    </dialog>
@endforeach

<style>
    .dashboard-dialog::backdrop { background: rgb(15 23 42 / 55%); }
    @media (max-width: 639px) {
        .dashboard-dialog[open] { position: fixed; inset: auto 0 0; width: 100%; max-width: 100%; max-height: 90vh; margin: 0; border-radius: 1rem 1rem 0 0; }
    }
    [data-award-panel][hidden], [data-monitor-row][hidden], [data-monitor-item][hidden] { display: none !important; }
</style>
<script>
    (() => {
        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-dashboard-open]');
            if (opener) {
                const dialog = document.getElementById(opener.dataset.dashboardOpen);
                if (dialog instanceof HTMLDialogElement && !dialog.open) dialog.showModal();
            }
            const closer = event.target.closest('[data-dashboard-close]');
            if (closer) closer.closest('dialog')?.close();
        });

        const filterMonitor = (key) => {
            const team = document.querySelector('[data-monitor-filter="' + key + '"]')?.value || 'ALL';
            const status = document.querySelector('[data-monitor-status="' + key + '"]')?.value || 'ALL';
            const search = (document.querySelector('[data-monitor-search="' + key + '"]')?.value || '').trim().toLowerCase();
            document.querySelectorAll('[data-monitor-row="' + key + '"]').forEach((row) => {
                row.hidden = team !== 'ALL' && row.dataset.monitorTeam !== team;
            });
            document.querySelectorAll('[data-monitor-item="' + key + '"]').forEach((row) => {
                row.hidden = (team !== 'ALL' && row.dataset.monitorTeam !== team)
                    || (status !== 'ALL' && row.dataset.monitorItemStatus !== status)
                    || (search !== '' && !(row.dataset.monitorSearchtext || '').includes(search));
            });
        };
        document.querySelectorAll('[data-monitor-filter], [data-monitor-status], [data-monitor-search]').forEach((input) => {
            input.addEventListener('input', () => filterMonitor(
                input.dataset.monitorFilter || input.dataset.monitorStatus || input.dataset.monitorSearch,
            ));
        });

        document.querySelectorAll('[data-award-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                const selected = tab.dataset.awardTab;
                document.querySelectorAll('[data-award-tab]').forEach((item) => {
                    const active = item.dataset.awardTab === selected;
                    item.setAttribute('aria-selected', String(active));
                    item.classList.toggle('bg-white', active);
                    item.classList.toggle('text-blue-700', active);
                    item.classList.toggle('shadow-sm', active);
                    item.classList.toggle('text-slate-600', !active);
                });
                document.querySelectorAll('[data-award-panel]').forEach((panel) => {
                    panel.hidden = panel.dataset.awardPanel !== selected;
                });
            });
        });
    })();
</script>
@endsection