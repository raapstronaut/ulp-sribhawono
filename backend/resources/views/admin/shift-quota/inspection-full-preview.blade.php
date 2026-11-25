@extends('layouts.admin')

@section('title', 'Pratinjau kandidat INSPEKSI (baca-saja)')

@section('content')
<div class="mx-auto max-w-7xl space-y-5 pb-12">
    <x-admin.posko-tabs />
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <h1 class="text-lg font-bold">Pratinjau kandidat INSPEKSI · Baca-saja</h1>
        <p class="mt-2">Halaman ini tidak membuat WO, tidak menjalankan generator legacy, dan tidak mencatat run/kuota. Ini hanya estimasi dari temuan INSPEKSI yang sudah ACC Admin untuk tim POSKO, mapping Section–POSKO, dan target WO yang masih aktif.</p>
        <p class="mt-1 font-semibold">Generator otomatis belum diaktifkan. Jatah sistem 6 WO/POSKO/shift, terpisah dari WO manual dan limpahan.</p>
    </div>

    <form method="GET" action="{{ route('admin.shift-quota.inspection-full') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-sm font-medium text-slate-700">Tanggal operasional
            <input type="date" name="operational_date" value="{{ $date }}" required class="mt-1 block rounded-md border border-slate-300 p-2" />
        </label>
        <label class="text-sm font-medium text-slate-700">Shift
            <select name="shift_code" class="mt-1 block rounded-md border border-slate-300 p-2">
                @foreach ($shiftOptions as $key => $value)
                    <option value="{{ $key }}" @selected($shift === $key)>{{ $value }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded-md bg-blue-700 px-4 py-2 font-semibold text-white">Perbarui pratinjau (baca-saja)</button>
        <a class="text-sm font-semibold text-blue-700 underline" href="{{ route('admin.shift-quota.index', ['operational_date' => $date, 'shift_code' => $shift]) }}">Kembali ke monitor kuota lama</a>
    </form>

    @if ($preview['blocked_by_legacy'])
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            <strong>Seluruh shift ditahan.</strong> Ada WO SYSTEM atau alokasi dari generator lama pada shift asal ini. Tidak ada rencana WO baru untuk semua POSKO, termasuk bila POSKO pemilik catatan lama sudah tidak aktif. Kandidat layak tetap ditampilkan sebagai jumlah untuk peninjauan, bukan izin menerbitkan WO.
        </div>
    @endif

    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="text-base font-bold text-slate-900">Rencana kuota · {{ $date }} · Shift {{ $shift }}</h2>
        <p class="mt-1 text-xs text-slate-600">Sumber INSPEKSI disetujui untuk POSKO: {{ $preview['source_total'] }} · kandidat layak: {{ $preview['eligible'] }} · ditahan: {{ count($preview['held']) }}. Baris SYSTEM menghitung asal shift generate, termasuk WO yang sudah dibatalkan, agar tidak mengisi kuota dua kali secara tersembunyi.</p>
        <table class="mt-3 w-full min-w-[700px] text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase text-slate-600"><tr>
                <th class="p-2">POSKO</th><th class="p-2">Sistem sudah dibuat</th>
                <th class="p-2">WO lain / manual / limpahan</th><th class="p-2">Rencana baru</th>
                <th class="p-2">Sistem setelah rencana</th><th class="p-2">Kekurangan</th>
            </tr></thead>
            <tbody>
                @foreach ($preview['rows'] as $row)
                    <tr class="border-b border-slate-100">
                        <td class="p-2 font-semibold">{{ $row['posko'] }}</td>
                        <td class="p-2">{{ $row['system_existing'] }}</td>
                        <td class="p-2">{{ $row['manual_other'] }}</td>
                        <td class="p-2 font-semibold text-blue-700">{{ $row['new'] }}</td>
                        <td class="p-2">{{ $row['system_after'] }} / 6</td>
                        <td class="p-2">{{ $row['shortfall'] }}</td>
                    </tr>
                    @if ($row['legacy_system_conflict'])
                        <tr><td colspan="6" class="px-2 pb-3 text-xs text-red-700">Ditahan: ada WO SYSTEM SPS legacy pada shift asal ini; kandidat tidak direncanakan.</td></tr>
                    @endif
                    @if ($row['legacy_allocation_conflict'])
                        <tr><td colspan="6" class="px-2 pb-3 text-xs text-red-700">Ditahan: alokasi shift dari generator SPS lama sudah tercatat, bahkan jika jumlah WO nol. Alokasi lama tidak akan ditimpa.</td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="text-base font-bold text-slate-900">Kandidat direncanakan (tidak diterbitkan sebagai WO)</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead class="border-b bg-slate-50 text-xs uppercase"><tr><th class="p-2">POSKO</th><th class="p-2">No. tiang</th><th class="p-2">Penyulang</th><th class="p-2">Section</th><th class="p-2">Kebutuhan (bh)</th><th class="p-2">Asal</th></tr></thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        @foreach ($row['planned'] as $item)
                            <tr class="border-b border-slate-100">
                                <td class="p-2">{{ $row['posko'] }}</td>
                                <td class="p-2 font-semibold">{{ $item['no_tiang'] }}</td>
                                <td class="p-2">{{ $item['penyulang'] }}</td>
                                <td class="p-2">{{ $item['section'] }}</td>
                                <td class="p-2">{{ $item['quantity'] }}</td>
                                <td class="p-2"><a class="text-blue-700 underline" href="{{ route('admin.inspeksi.show', $item['inspection_id']) }}">Lihat inspeksi</a></td>
                            </tr>
                        @endforeach
                    @endforeach
                    @if (array_sum(array_column($preview['rows'], 'new')) === 0)
                        <tr><td colspan="6" class="p-3 text-slate-500">{{ $preview['blocked_by_legacy'] ? 'Tidak ada rencana baru karena seluruh shift ditahan.' : 'Tidak ada kandidat yang masuk rencana pada shift ini.' }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="text-base font-bold text-slate-900">Kandidat ditahan ({{ count($preview['held']) }})</h2>
        <p class="mt-1 text-xs text-slate-600">Tabel menampilkan maksimal 100 baris. Alasan yang tidak jelas perlu divalidasi dari data sumber, bukan diasumsikan.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[650px] text-left text-sm">
                <thead class="border-b bg-slate-50 text-xs uppercase"><tr><th class="p-2">No. tiang</th><th class="p-2">Penyulang</th><th class="p-2">Section</th><th class="p-2">Alasan</th><th class="p-2">Asal</th></tr></thead>
                <tbody>
                    @forelse (array_slice($preview['held'], 0, 100) as $held)
                        <tr class="border-b border-slate-100">
                            <td class="p-2">{{ $held['no_tiang'] ?? '-' }}</td>
                            <td class="p-2">{{ $held['penyulang'] ?? '-' }}</td>
                            <td class="p-2">{{ $held['section'] ?? '-' }}</td>
                            <td class="p-2 font-semibold text-amber-800">{{ str_replace('_', ' ', $held['reason']) }}</td>
                            <td class="p-2"><a class="text-blue-700 underline" href="{{ route('admin.inspeksi.show', $held['inspection_id']) }}">Lihat inspeksi</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-3 text-slate-500">Tidak ada kandidat ditahan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
