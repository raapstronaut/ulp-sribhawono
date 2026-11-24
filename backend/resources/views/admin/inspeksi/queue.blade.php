@extends('layouts.admin')

@section('title', 'Antrean Temuan ACC')

@section('content')
<div class="mx-auto max-w-7xl space-y-4 pb-10">
    <div class="pln-workspace-panel">
        <div class="pln-panel-title">PEKERJAAN / INSPEKSI / ANTREAN TEMUAN ACC</div>
        <div class="pln-panel-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Antrean Temuan INSPEKSI yang Sudah ACC</h1>
                <p class="mt-1 text-sm text-slate-600">Temuan disetujui Admin yang belum memiliki tautan WO tindak lanjut.</p>
            </div>
            <a href="{{ route('admin.inspeksi.index') }}" class="text-sm font-semibold text-blue-700 underline">Kembali ke INSPEKSI</a>
        </div>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950">
            <strong>Hanya baca, belum antrean siap-generate.</strong> Saran tim belum tentu keputusan akhir. WO lama mungkin belum tertaut pada temuan; validasi target, ownership Section, konflik, kuota, dan jadwal belum dilakukan di halaman ini. Tidak ada WO yang diterbitkan.
        </div>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.inspeksi.queue') }}" class="pln-workspace-panel">
        <div class="pln-panel-title">FILTER ANTREAN</div>
        <div class="pln-panel-body flex flex-wrap items-end gap-3">
        <label class="block text-xs font-semibold text-slate-700">Saran tim
            <select name="team" class="mt-1 block min-w-44 pln-field">
                <option value="">Semua tim dan belum ditentukan</option>
                @foreach (['POSKO', 'ROW', 'HARSUS'] as $item)
                    <option value="{{ $item }}" @selected($team === $item)>{{ $item }}</option>
                @endforeach
            </select>
        </label>
        <div class="ml-auto text-sm font-semibold text-slate-700">{{ number_format($findings->total(), 0, ',', '.') }} temuan belum tertaut</div>
        </div>
        <div class="pln-action-row">
            <button class="pln-btn pln-btn-primary" type="submit">Search</button>
            <a class="pln-btn" href="{{ route('admin.inspeksi.queue') }}">Reset</a>
        </div>
    </form>

    <div class="pln-workspace-panel overflow-x-auto">
        <div class="pln-panel-title">HASIL ANTREAN</div>
        <table class="w-full min-w-[1000px] text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase text-slate-600">
                <tr>
                    <th class="px-4 py-3">Temuan</th><th class="px-4 py-3">Penyulang / Section</th>
                    <th class="px-4 py-3">Saran Tim</th><th class="px-4 py-3">Status peninjauan</th><th class="px-4 py-3">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($findings as $finding)
                    <tr class="border-b border-slate-100 align-top hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $finding->finding_code }}</div>
                            <div class="mt-1 max-w-xs text-xs text-slate-600">{{ $finding->description }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $finding->inspection?->penyulang?->nama_penyulang ?? 'Belum diketahui' }}
                            <div class="text-slate-500">{{ $finding->inspection?->section_name ?? 'Section belum tersedia' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $finding->suggested_team_type ?? 'Belum ditentukan' }}</td>
                        <td class="px-4 py-3 text-xs">
                            <span class="rounded bg-amber-100 px-2 py-1 font-semibold text-amber-900">Perlu peninjauan</span>
                            <div class="mt-2 max-w-xs text-slate-600">{{ \App\Http\Controllers\Admin\ApprovedFindingQueueController::reviewReason($finding) }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if ($finding->inspection_id)
                                <a class="font-semibold text-blue-700 underline" href="{{ route('admin.inspeksi.show', $finding->inspection_id) }}#temuan-{{ $finding->id }}">Detail INSPEKSI</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada temuan ACC tanpa tautan WO pada filter ini.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $findings->links() }}</div>
    </div>
</div>
@endsection
