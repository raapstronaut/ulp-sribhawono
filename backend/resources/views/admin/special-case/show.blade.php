@extends('layouts.admin')

@section('title', 'Detail Special Case')

@section('content')
@php
    $dimensionLabel = \App\Http\Controllers\Admin\SpecialCaseController::DIMENSIONS[$filters['dimension']];
    $keyLabel = $key !== '' ? $key : '(kosong)';
    $fromLabel = \Illuminate\Support\Carbon::parse($range['from'])->format('d/m/Y');
    $toLabel = \Illuminate\Support\Carbon::parse($range['to'])->format('d/m/Y');
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Kontrol"
        parent="Special Case"
        :parent-url="route('admin.special-case.index', ['dimension' => $filters['dimension'], 'period' => $filters['period']])"
        :title="$dimensionLabel.': '.$keyLabel"
        state="live"
        :description="'Kejadian gangguan '.$fromLabel.' – '.$toLabel.'. Data dibaca dari tabel gangguans tanpa penandaan special case.'"
    />

    <x-admin.panel flush :title="'Kejadian gangguan · '.$keyLabel" description="Lokasi kejadian belum tersedia karena tabel gangguan belum memiliki koordinat; aset terdampak belum terhubung.">
        @if ($load->failed('events'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[1100px]">
                <x-slot:head>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">ID gangguan</th>
                    <th class="px-4 py-3">Penyulang / Peralatan</th>
                    <th class="px-4 py-3">No. tiang</th>
                    <th class="px-4 py-3">Penyebab</th>
                    <th class="px-4 py-3">Sifat</th>
                    <th class="px-4 py-3 text-right">Padam (menit)</th>
                    <th class="px-4 py-3">Tindakan corrective</th>
                </x-slot:head>
                @forelse ($events as $event)
                    <tr class="align-top">
                        <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $event->tanggal_kejadian ? \Illuminate\Support\Carbon::parse($event->tanggal_kejadian)->format('d/m/Y') : '-' }}</td>
                        <td class="px-4 py-3 font-mono text-[11px]">{{ $event->id_gangguan }}</td>
                        <td class="px-4 py-3"><div class="font-medium text-slate-800">{{ $event->penyulang ?? '-' }}</div><div class="text-[10px] text-slate-500">{{ $event->peralatan ?? '-' }}</div></td>
                        <td class="px-4 py-3 text-xs">{{ $event->no_tiang ?? '-' }}</td>
                        <td class="px-4 py-3"><div class="text-xs font-semibold text-slate-800">{{ $event->penyebab ?? '-' }}</div><div class="max-w-xs text-[11px] text-slate-500">{{ \Illuminate\Support\Str::limit((string) $event->uraian_penyebab, 140) }}</div></td>
                        <td class="px-4 py-3 text-xs">{{ $event->sifat_gangguan ?? '-' }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $event->lama_padam_menit !== null ? number_format((float) $event->lama_padam_menit, 0, ',', '.') : '-' }}</td>
                        <td class="max-w-xs px-4 py-3 text-[11px] text-slate-600">{{ \Illuminate\Support\Str::limit((string) $event->tindakan_corrective, 160) ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-admin.empty-state title="Tidak ada kejadian." description="Tidak ada gangguan untuk kelompok ini pada periode yang dipilih." /></td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$events" label="kejadian gangguan" />
        @endif
    </x-admin.panel>
</div>
@endsection
