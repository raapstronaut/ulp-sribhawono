@extends('layouts.admin')

@section('title', 'Buat WO APKUK')

@section('content')
@php
    $listQuery = array_filter([
        'penyulang_id' => $filters['penyulang_id'],
        'q' => $filters['q'],
        'show' => $filters['show'] === 'all' ? 'all' : null,
        'page' => $candidates->currentPage() > 1 ? $candidates->currentPage() : null,
    ]);
@endphp
<div class="max-w-7xl mx-auto space-y-6 pb-12">
    <x-admin.posko-tabs />
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-1">
            <nav aria-label="Breadcrumb" class="text-xs font-semibold text-slate-500">Pekerjaan / POSKO / <a class="text-blue-700 hover:underline" href="{{ route('admin.work-orders.index') }}">WO APKUK</a> / Buat WO</nav>
            <h1 class="text-2xl font-black tracking-tight text-slate-900">Buat WO APKUK Manual</h1>
            <p class="max-w-3xl text-sm text-slate-600">Tentukan kandidat tiang yang sah, pilih POSKO dan jadwal, lalu terbitkan pekerjaan.</p>
        </div>
        <a href="{{ route('admin.work-orders.index') }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Kembali ke daftar WO</a>
    </header>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Tahapan pembuatan WO">
        <a href="#kandidat" class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm hover:border-blue-300">
            <div class="text-xs font-bold text-blue-700">Langkah 1</div><div class="mt-1 text-sm font-semibold text-slate-900">Pilih kandidat APKUK</div><div class="mt-1 text-xs text-slate-500">Data sumber yang lolos validasi</div>
        </a>
        <a href="{{ $selected ? '#penugasan' : '#kandidat' }}" class="rounded-xl border {{ $selected ? 'border-blue-200 bg-blue-50' : 'border-slate-200 bg-white' }} px-4 py-3 shadow-sm">
            <div class="text-xs font-bold {{ $selected ? 'text-blue-700' : 'text-slate-500' }}">Langkah 2</div><div class="mt-1 text-sm font-semibold text-slate-900">Atur penugasan</div><div class="mt-1 text-xs text-slate-500">POSKO, tanggal operasional dan shift</div>
        </a>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs font-bold text-slate-500">Langkah 3</div><div class="mt-1 text-sm font-semibold text-slate-900">Konfirmasi & terbitkan</div><div class="mt-1 text-xs text-slate-500">Periksa informasi sebelum menyimpan</div>
        </div>
    </div>
    <!-- Step 2: selected candidate + form -->
    @if($selected)
        @php $a = $selected->apkuk; @endphp
        <section id="penugasan" class="scroll-mt-5 rounded-xl border bg-white shadow-sm {{ $selected->isEligible() ? 'border-blue-200' : 'border-red-200' }}">
            <div class="p-5 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-blue-700">Langkah 2 · Lokasi terpilih</p>
                    <h2 class="text-lg font-bold text-slate-900">{{ $a->no_tiang }}</h2>
                    <p class="text-xs text-slate-500">{{ $selected->penyulang?->nama_penyulang ?? $a->penyulang }} • {{ $selected->sectionName ?? 'Section belum diketahui' }}</p>
                </div>
                <a href="{{ route('admin.work-orders.create', $listQuery) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Ganti lokasi</a>
            </div>
            <div class="p-5 grid grid-cols-2 md:grid-cols-6 gap-4 text-sm">
                <div><div class="text-xs text-slate-500">Target APKUK</div><div class="text-xl font-black text-slate-900">{{ $a->kurang_bh ?? '-' }} <span class="text-xs font-semibold text-slate-500">bh</span></div></div>
                <div><div class="text-xs text-slate-500">Terpasang (sumber)</div><div class="font-semibold text-slate-900">{{ $a->terpasang_bh ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Status sumber</div><div class="font-semibold text-slate-900">{{ $a->status ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Tanggal laporan</div><div class="font-semibold text-slate-900">{{ $a->tanggal?->format('d/m/Y') ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Naik dari</div><div class="font-semibold text-slate-900">{{ $a->naik_dari ?? '-' }}</div></div>
                <div><div class="text-xs text-slate-500">Koordinat</div><div class="font-semibold text-slate-900">{{ $a->latitude && $a->longitude ? $a->latitude.', '.$a->longitude : '-' }}</div></div>
            </div>
            @if($selected->warnings)
                <div class="mx-5 mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Catatan data: {{ implode('; ', $selected->warningLabels()) }}.
                </div>
            @endif

            @if($errors->any())
                <div role="alert" class="mx-5 mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-800">
                    <strong>WO belum bisa dibuat. Periksa:</strong>
                    <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                </div>
            @endif
            @if($selected->isEligible())
                <form method="POST" action="{{ route('admin.work-orders.store') }}" data-confirm="Terbitkan WO APKUK untuk tiang {{ $a->no_tiang }}? Pastikan POSKO, tanggal operasional, dan shift sudah benar." class="p-5 border-t border-slate-100 grid grid-cols-1 md:grid-cols-4 gap-4">
                    @csrf
                    <input type="hidden" name="apkuk_id" value="{{ $a->id }}">

                    <div>
                        <label for="posko_id" class="mb-1 block text-xs font-semibold text-slate-700">POSKO penanggung jawab <span class="text-red-600">*</span></label>
                        <select id="posko_id" name="posko_id" required class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('posko_id') ? 'border-red-400' : 'border-slate-300' }}">
                            <option value="">— Pilih POSKO —</option>
                            @foreach($poskos as $posko)
                                <option value="{{ $posko->id }}" @selected(old('posko_id') === $posko->id)>{{ $posko->nama_posko }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[10px] text-slate-500">Dipilih manual; sistem tidak menebak POSKO dari penyulang.</p>
                    </div>
                    <div>
                        <label for="work_date" class="mb-1 block text-xs font-semibold text-slate-700">Tanggal operasional <span class="text-red-600">*</span></label>
                        <input id="work_date" type="date" name="work_date" required min="{{ $default_work_date }}" value="{{ old('work_date', $default_work_date) }}" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('work_date') ? 'border-red-400' : 'border-slate-300' }}">
                        <p class="mt-1 text-[10px] text-slate-500">Hari operasional dimulai pukul 08.00 WIB; Shift C setelah pukul 00.00 masih memakai tanggal operasional sebelumnya.</p>
                    </div>
                    <div>
                        <label for="shift_code" class="mb-1 block text-xs font-semibold text-slate-700">Shift <span class="text-red-600">*</span></label>
                        <select id="shift_code" name="shift_code" required class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('shift_code') ? 'border-red-400' : 'border-slate-300' }}">
                            @foreach($shift_options as $option)
                                <option value="{{ $option['code'] }}" @selected(old('shift_code', $default_shift) === $option['code'])>Shift {{ $option['code'] }} — {{ $option['name'] }} ({{ $option['time'] }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="notes" class="mb-1 block text-xs font-semibold text-slate-700">Catatan (opsional)</label>
                        <input id="notes" type="text" name="notes" maxlength="1000" value="{{ old('notes') }}" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('notes') ? 'border-red-400' : 'border-slate-300' }}">
                    </div>
                    <div class="md:col-span-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs leading-relaxed text-blue-900">
                        <strong>Konfirmasi penerbitan:</strong> Lokasi <strong>{{ $a->no_tiang }}</strong> dengan target <strong>{{ $a->kurang_bh }} bh</strong>. Pastikan POSKO, tanggal operasional dan shift yang dipilih sudah benar. Setelah konfirmasi, sistem tetap memeriksa kandidat, jadwal dan kuota.
                    </div>
                    <div class="md:col-span-4 flex justify-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold shadow-sm">Buat WO APKUK</button>
                    </div>
                </form>
            @else
                <div class="p-5 border-t border-slate-100 text-sm text-red-700">
                    <p class="font-semibold">Lokasi ini tidak dapat dibuatkan WO:</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach($selected->issueLabels() as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ul>
                    @if($selected->activeWorkOrderCode)
                        <p class="mt-2 text-xs text-slate-600">WO aktif: <strong>{{ $selected->activeWorkOrderCode }}</strong></p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-bold text-slate-900">Langkah 1 · Pilih kandidat APKUK</h2>
            <p class="mt-1 text-xs text-slate-500">Data sumber tidak otomatis menjadi WO. Hanya baris valid yang boleh dipilih untuk penerbitan pekerjaan.</p>
        </div>
        <a href="{{ route('admin.apkuk.index') }}" class="text-xs font-bold text-blue-700 hover:underline">Lihat Data Sumber APKUK →</a>
    </div>

    <!-- Data quality summary -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Kandidat valid</span>
            <div class="text-3xl font-black text-slate-800 mt-1">{{ $summary['eligible'] }}</div>
            <div class="mt-2 text-[10px] text-slate-500">dari {{ $summary['total'] }} baris APKUK tersinkron</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm lg:col-span-2">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Tidak dijadikan kandidat (satu baris bisa punya beberapa alasan)</span>
            <ul class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-600">
                @forelse($summary['issues'] as $code => $count)
                    <li class="flex justify-between gap-3"><span>{{ $issue_labels[$code] ?? $code }}</span><strong class="text-slate-800">{{ $count }}</strong></li>
                @empty
                    <li>Tidak ada.</li>
                @endforelse
            </ul>
        </div>
    </section>

    <!-- Step 1: candidate list -->
    <section id="kandidat" class="scroll-mt-5 rounded-xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" action="{{ route('admin.work-orders.create') }}" class="p-4 border-b border-slate-200 bg-slate-50/50 flex flex-col lg:flex-row gap-3 items-center">
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Cari no. tiang atau section..." class="flex-1 w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <select name="penyulang_id" aria-label="Penyulang" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600">
                <option value="">Semua Penyulang</option>
                @foreach($penyulangs as $penyulang)
                    <option value="{{ $penyulang->id }}" @selected($filters['penyulang_id'] === $penyulang->id)>{{ $penyulang->nama_penyulang }}</option>
                @endforeach
            </select>
            <select name="show" aria-label="Tampilkan" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600">
                <option value="eligible" @selected($filters['show'] === 'eligible')>Hanya kandidat valid</option>
                <option value="all" @selected($filters['show'] === 'all')>Semua baris + alasan</option>
            </select>
            <button type="submit" class="bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600 min-w-[960px]">
                <thead class="text-[10px] uppercase font-bold text-slate-500 border-b border-slate-200 tracking-wider">
                    <tr>
                        <th class="px-4 py-3">No. Tiang</th>
                        <th class="px-4 py-3">Penyulang / Section</th>
                        <th class="px-4 py-3 text-center">Kurang bh</th>
                        <th class="px-4 py-3">Status sumber</th>
                        <th class="px-4 py-3">Tgl laporan</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($candidates as $candidate)
                        @php $a = $candidate->apkuk; @endphp
                        <tr class="{{ $selected && $selected->apkuk->id === $a->id ? 'bg-blue-50/60' : '' }}">
                            <td class="px-4 py-3 font-semibold text-slate-800">{{ $a->no_tiang }}</td>
                            <td class="px-4 py-3">
                                <div>{{ $candidate->penyulang?->nama_penyulang ?? $a->penyulang ?? '-' }}</div>
                                <div class="text-[10px] text-slate-500">{{ $candidate->sectionName ?? 'Section belum diketahui' }}</div>
                            </td>
                            <td class="px-4 py-3 text-center font-bold {{ $candidate->isEligible() ? 'text-slate-900' : 'text-slate-400' }}">{{ $a->kurang_bh ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $a->status ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $a->tanggal?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-4 py-3 text-[11px]">
                                @if($candidate->isEligible())
                                    <span class="text-emerald-700 font-semibold">Valid</span>
                                    @if($candidate->warnings)
                                        <span class="block text-amber-700">{{ implode('; ', $candidate->warningLabels()) }}</span>
                                    @endif
                                @else
                                    <span class="text-red-700">{{ implode('; ', $candidate->issueLabels()) }}</span>
                                    @if($candidate->activeWorkOrderCode)
                                        <span class="block text-slate-500">{{ $candidate->activeWorkOrderCode }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($candidate->isEligible())
                                    <a href="{{ route('admin.work-orders.create', array_merge($listQuery, ['apkuk_id' => $a->id])) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Pilih</a>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Tidak ada data APKUK yang cocok dengan filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <span class="text-slate-500">Menampilkan {{ $candidates->count() }} dari {{ $candidates->total() }} baris.</span>
            @if($candidates->hasPages())
                <div class="flex items-center gap-2">
                    @if($candidates->onFirstPage())
                        <span class="px-3 py-1.5 rounded border border-slate-200 text-slate-400 bg-slate-50">Sebelumnya</span>
                    @else
                        <a href="{{ $candidates->previousPageUrl() }}" class="px-3 py-1.5 rounded border border-slate-200 text-slate-700 bg-white hover:bg-slate-50">Sebelumnya</a>
                    @endif
                    <span class="text-slate-500">Hal. {{ $candidates->currentPage() }} / {{ $candidates->lastPage() }}</span>
                    @if($candidates->hasMorePages())
                        <a href="{{ $candidates->nextPageUrl() }}" class="px-3 py-1.5 rounded border border-slate-200 text-slate-700 bg-white hover:bg-slate-50">Berikutnya</a>
                    @else
                        <span class="px-3 py-1.5 rounded border border-slate-200 text-slate-400 bg-slate-50">Berikutnya</span>
                    @endif
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
