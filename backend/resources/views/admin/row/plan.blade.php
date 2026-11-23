@extends('layouts.admin')

@section('title', 'Perencanaan ROW')

@section('content')
<div class="mx-auto max-w-7xl space-y-4 pb-10">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Perencanaan WO ROW dari INSPEKSI</h1>
                <p class="mt-1 text-sm text-slate-600">Admin memilih RABAS, PANGKAS, atau TEBANG dan tim ROW penerima setelah temuan INSPEKSI disetujui. <strong>Perencanaan tidak menerbitkan WO.</strong></p>
            </div>
            <a href="{{ route('admin.row.index') }}" class="text-sm font-semibold text-blue-700 underline">Kembali ke ROW</a>
        </div>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">Generator ROW otomatis belum diaktifkan. Target maksimal 10 WO SYSTEM per tim ROW per hari, jika kandidat valid cukup. WO manual Admin terpisah.</div>
    </div>

    @unless ($ready)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-900">
            Struktur penyimpanan rencana ROW belum diterapkan ke PostgreSQL lokal. Halaman ini sengaja belum menerima input. Tidak ada migrasi atau WO yang dibuat oleh installer v10.
        </div>
    @else
        @if(session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        <form method="GET" action="{{ route('admin.row.plan') }}" class="rounded-lg border border-slate-200 bg-white p-3">
            <label class="text-sm font-semibold text-slate-700">Tanggal target ROW (untuk ringkasan kuota)
                <input type="date" name="work_date" value="{{ $date }}" class="ml-2 rounded border border-slate-300 p-2" />
            </label>
            <button type="submit" class="ml-2 rounded bg-blue-700 px-3 py-2 text-sm font-semibold text-white">Perbarui</button>
        </form>
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <strong>Pratinjau ROW v12 (hanya baca)</strong>
                    <p class="mt-1 text-xs">Periksa rencana Admin, target ganda, WO aktif, dan slot 10 WO SYSTEM per tim untuk tanggal yang dipilih. Tidak menyimpan data atau membuat WO.</p>
                </div>
                <a href="{{ route('admin.row.plan.preview', ['work_date' => $date]) }}" class="rounded bg-blue-700 px-4 py-2 text-xs font-bold text-white">Lihat pratinjau v12</a>
            </div>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="font-bold text-slate-900">Kuota harian per tim ROW · {{ $date }}</h2>
            <p class="mt-1 text-xs text-slate-500">Rencana yang diklasifikasi belum tentu layak menjadi WO: pemeriksaan lokasi ganda, WO aktif, serta aturan penjadwalan tetap diperlukan saat generator diintegrasikan.</p>
            <table class="mt-3 w-full min-w-[650px] text-sm">
                <thead class="border-b bg-slate-50 text-left"><tr><th class="p-2">Tim ROW</th><th class="p-2">WO SYSTEM tercatat</th><th class="p-2">WO manual</th><th class="p-2">Temuan direncanakan*</th><th class="p-2">Slot SYSTEM tersisa</th></tr></thead>
                <tbody>
                    @forelse ($quota as $row)
                        <tr class="border-b"><td class="p-2 font-semibold">{{ $row['code'] }}</td><td class="p-2">{{ $row['system'] }} / {{ $row['target'] }}</td><td class="p-2">{{ $row['manual'] }}</td><td class="p-2">{{ $row['prepared'] }}</td><td class="p-2">{{ $row['remaining_system_slots'] }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="p-3 text-slate-500">Belum ada tim ROW aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="mt-2 text-[11px] text-slate-500">*Rencana berlaku hingga temuan memiliki WO; bukan kuota yang dijadwalkan untuk tanggal ini.</p>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="font-bold text-slate-900">Temuan ROW disetujui, belum memiliki WO (maksimal 100 terbaru antrean)</h2>
            <p class="mt-1 text-xs text-slate-500">Tim INSPEKSI hanya memilih tujuan ROW. Jenis kerja dan tim ROW penerima diputuskan Admin pada tahap ini.</p>
            <table class="mt-3 w-full min-w-[900px] text-sm">
                <thead class="border-b bg-slate-50 text-left"><tr><th class="p-2">Temuan</th><th class="p-2">Penyulang / Section</th><th class="p-2">Deskripsi</th><th class="p-2">Rencana Admin (belum WO)</th></tr></thead>
                <tbody>
                    @forelse ($findings as $finding)
                        @php $current = $plans->get($finding->id); @endphp
                        <tr class="border-b align-top">
                            <td class="p-2"><a href="{{ route('admin.inspeksi.show', $finding->inspection_id) }}" class="font-semibold text-blue-700 underline">{{ $finding->finding_code }}</a><div class="mt-1 text-xs text-slate-500">{{ substr($finding->id, 0, 8) }}</div></td>
                            <td class="p-2">{{ $finding->inspection?->penyulang?->nama_penyulang ?? '-' }}<div class="text-xs text-slate-500">{{ $finding->inspection?->section_name ?? '-' }}</div></td>
                            <td class="max-w-sm p-2">{{ $finding->description }}</td>
                            <td class="p-2">
                                <form method="POST" action="{{ route('admin.row.plan.store', $finding) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="work_date" value="{{ $date }}" />
                                    <select name="work_type" required class="rounded border border-slate-300 p-2 text-xs">
                                        <option value="">Pilih pekerjaan</option>
                                        @foreach (\App\Models\WorkOrder::ROW_WORK_TYPES as $type)
                                            <option value="{{ $type }}" @selected($current?->work_type === $type)>{{ $type }}</option>
                                        @endforeach
                                    </select>
                                    <select name="field_team_id" required class="rounded border border-slate-300 p-2 text-xs">
                                        <option value="">Pilih tim ROW</option>
                                        @foreach ($teams as $team)
                                            <option value="{{ $team->id }}" @selected($current?->field_team_id === $team->id)>{{ $team->code }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="rounded bg-blue-700 px-3 py-2 text-xs font-semibold text-white">Simpan rencana</button>
                                </form>
                                @error('row_plan')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                @error('field_team_id')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                @error('work_type')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-3 text-slate-500">Tidak ada temuan ROW ACC Admin yang belum mempunyai WO.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endunless
</div>
@endsection
