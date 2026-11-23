@extends('layouts.admin')

@section('title', 'Pratinjau ROW v12')

@section('content')
@php
    $labels = [
        'SUDAH_MEMILIKI_WO' => 'Temuan sudah punya WO',
        'PENYULANG_TIDAK_AKTIF' => 'Penyulang tidak aktif / tidak tersedia',
        'TARGET_TIDAK_VALID' => 'Target lokasi tidak valid',
        'TEMUAN_GANDA_SATU_TITIK' => 'Temuan ganda pada satu titik',
        'WO_ROW_AKTIF_DI_TITIK' => 'Masih ada WO ROW aktif pada titik tersebut',
        'BUTUH_JENIS_DAN_TIM_ROW' => 'Belum ada klasifikasi dan tim ROW aktif',
        'KUOTA_HARIAN_PENUH' => 'Melebihi slot 10 WO SYSTEM per tim',
    ];
    $generated = collect($report['rows'])->sum('new');
    $existing = collect($report['rows'])->sum('system_existing');
    $manual = collect($report['rows'])->sum('manual');
    $heldCount = count($report['held']);
@endphp
<div class="mx-auto max-w-7xl space-y-4 pb-10">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Pratinjau generator ROW · {{ $date }}</h1>
                <p class="mt-1 text-sm text-slate-600">Rencana Admin dipakai hanya untuk simulasi kandidat. Ini <strong>bukan jadwal atau penerbitan WO</strong>.</p>
            </div>
            <a href="{{ route('admin.row.plan', ['work_date' => $date]) }}" class="text-sm font-semibold text-blue-700 underline">Kembali ke perencanaan</a>
        </div>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
            Pratinjau V12 hanya baca, generator dan scheduler NONAKTIF. Tidak ada data yang disimpan. Target maksimal 10 WO SYSTEM/tim/hari, WO manual di luar kuota otomatis. Tidak ada jadwal atau batas waktu yang ditebak.
        </div>
        <form method="GET" action="{{ route('admin.row.plan.preview') }}" class="mt-4 flex flex-wrap items-center gap-2 text-sm">
            <label class="font-semibold" for="work_date">Tanggal ROW</label>
            <input id="work_date" name="work_date" type="date" required value="{{ $date }}" class="rounded border border-slate-300 p-2" />
            <button type="submit" class="rounded bg-blue-700 px-4 py-2 text-xs font-bold text-white">Hitung ulang</button>
        </form>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">WO SYSTEM tercatat</div><div class="text-2xl font-bold">{{ $existing }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Calon WO SYSTEM (bukan WO nyata)</div><div class="text-2xl font-bold">{{ $generated }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">WO manual (tidak memotong kuota)</div><div class="text-2xl font-bold">{{ $manual }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Temuan ditahan</div><div class="text-2xl font-bold">{{ $heldCount }}</div></div>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white p-4">
        <h2 class="font-bold text-slate-900">Kuota dan kelayakan per tim ROW</h2>
        <table class="mt-3 w-full min-w-[620px] text-sm">
            <thead class="border-b bg-slate-50 text-left"><tr><th class="p-2">Tim ROW</th><th class="p-2">SYSTEM tercatat</th><th class="p-2">Manual</th><th class="p-2">Calon SYSTEM</th><th class="p-2">Kekurangan kandidat valid</th></tr></thead>
            <tbody>
                @forelse ($report['rows'] as $row)
                    <tr class="border-b"><td class="p-2 font-semibold">{{ $row['team_code'] }}</td><td class="p-2">{{ $row['system_existing'] }} / {{ $row['target'] }}</td><td class="p-2">{{ $row['manual'] }}</td><td class="p-2">{{ $row['new'] }}</td><td class="p-2">{{ $row['shortfall'] }}</td></tr>
                @empty
                    <tr><td colspan="5" class="p-3 text-slate-500">Belum ada tim ROW aktif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white p-4">
        <h2 class="font-bold text-slate-900">Kandidat yang masuk slot (simulasi)</h2>
        <table class="mt-3 w-full min-w-[750px] text-sm">
            <thead class="border-b bg-slate-50 text-left"><tr><th class="p-2">Tim</th><th class="p-2">Temuan</th><th class="p-2">Jenis kerja</th><th class="p-2">Section</th><th class="p-2">Deskripsi</th></tr></thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    @foreach ($row['planned'] as $candidate)
                        <tr class="border-b"><td class="p-2">{{ $row['team_code'] }}</td><td class="p-2">{{ $candidate['finding_code'] }} ({{ substr($candidate['finding_id'], 0, 8) }})</td><td class="p-2">{{ $candidate['work_type'] }}</td><td class="p-2">{{ $candidate['section'] ?? '-' }}</td><td class="p-2">{{ $candidate['description'] }}</td></tr>
                    @endforeach
                @endforeach
                @if ($generated === 0)
                    <tr><td colspan="5" class="p-3 text-slate-500">Tidak ada kandidat siap pada tanggal ini. Kekurangan kuota bukan alasan untuk membuat WO fiktif.</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white p-4">
        <h2 class="font-bold text-slate-900">Temuan tertahan dan alasannya ({{ $heldCount }})</h2>
        @if ($heldCount > 0)
            <p class="mt-1 text-xs text-slate-500">Rincian menampilkan maksimal 100 entri; ringkasan alasan menghitung seluruh temuan yang ditahan.</p>
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                @foreach ($report['held_by_reason'] as $reason => $count)
                    <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1">{{ $labels[$reason] ?? $reason }}: <strong>{{ $count }}</strong></span>
                @endforeach
            </div>
            <table class="mt-3 w-full min-w-[620px] text-sm">
                <thead class="border-b bg-slate-50 text-left"><tr><th class="p-2">Temuan</th><th class="p-2">Alasan ditahan</th></tr></thead>
                <tbody>
                    @foreach (array_slice($report['held'], 0, 100) as $entry)
                        <tr class="border-b"><td class="p-2">{{ $entry['finding_code'] }} ({{ substr($entry['finding_id'], 0, 8) }})</td><td class="p-2">{{ $labels[$entry['reason']] ?? $entry['reason'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="mt-2 text-sm text-slate-500">Tidak ada temuan tertahan pada hasil pembacaan ini.</p>
        @endif
    </div>
</div>
@endsection
