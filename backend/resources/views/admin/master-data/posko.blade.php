@extends('layouts.admin')

@section('title', 'Posko '.$posko->nama_posko)

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        parent="Master Data"
        :parent-url="route('admin.master-data.index', ['tab' => 'posko'])"
        :title="$posko->nama_posko"
        state="live"
        description="Detail master Posko. Relasi Penyulang bersifat many-to-many dan tidak menjadi dasar kepemilikan aset."
    >
        <x-slot:actions>
            <a href="{{ route('admin.pasila.show', $posko) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Konteks PASILA</a>
            <a href="{{ route('admin.work-orders.index', ['posko_id' => $posko->id]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">WO APKUK Posko</a>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel title="Identitas">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode">{{ $posko->kode_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Nama">{{ $posko->nama_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Status">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="WO total / aktif">
                @if ($load->failed('work_orders'))
                    <span class="text-red-700">Gagal dimuat</span>
                @else
                    {{ $workOrders['total'] }} / {{ $workOrders['active'] }}
                @endif
            </x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <x-admin.panel title="Pratinjau rotasi pasangan POSKO" description="Tanggal operasional dimulai pukul 08.00 WIB. Jadwal ini dihitung dari roster yang tersimpan, belum mengaktifkan penugasan otomatis Android, PASILA, atau WO." data-crew-rotation-preview>
        <form method="GET" action="{{ route('admin.master-data.posko.show', $posko) }}" class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="posko-roster-date" class="block text-xs font-semibold text-slate-600">Mulai tanggal operasional</label>
                <input id="posko-roster-date" type="date" name="date" min="{{ $rosterAnchor ?: '' }}" value="{{ $rosterDate }}" class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Tampilkan 4 hari</button>
            @if ($rosterAnchor)
                <span class="pb-2 text-xs text-slate-500">Hari 1: {{ \Carbon\CarbonImmutable::parse($rosterAnchor)->format('d/m/Y') }}</span>
            @endif
        </form>

        @if ($load->failed('teams'))
            <x-admin.error-state :inset="false" />
        @elseif ($rosterAnchor === null)
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Tanggal acuan belum dikonfigurasi. Tidak ada penugasan yang ditampilkan.</p>
        @elseif ($poskoTeamCount !== 1)
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">POSKO ini harus memiliki tepat satu tim induk POSKO. Ditemukan {{ $poskoTeamCount }}; periksa Master Data tim.</p>
        @elseif (! $poskoTeamActive || $roster === null || ! $roster['roster_complete'])
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Tim induk nonaktif atau roster belum lengkap. Dibutuhkan tepat 4 pasangan aktif, masing-masing 2 petugas. Jadwal tidak dianggap valid.</p>
        @elseif (count($rosterDays) !== 4)
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Perhitungan rotasi tidak tersedia untuk tanggal yang dipilih.</p>
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-[790px] w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Pasangan petugas</th>
                            @foreach ($rosterDays as $day)
                                <th class="px-4 py-3 text-center">
                                    {{ \Carbon\CarbonImmutable::parse($day['date'])->format('d/m/Y') }}
                                    <span class="block text-[10px] font-normal text-slate-500">Hari rotasi {{ $day['cycle_day'] }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($roster['crews'] as $crew)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-900">TIM {{ $crew['number'] }}</div>
                                    <div class="mt-1 text-xs text-slate-600">{{ implode(' - ', array_column($crew['members'], 'name')) }}</div>
                                </td>
                                @foreach ($rosterDays as $day)
                                    @php $shiftName = $day['by_crew'][$crew['number']] ?? 'Tidak diketahui'; @endphp
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-block rounded-full px-3 py-1 text-xs font-semibold {{ $shiftName === 'Libur' ? 'bg-slate-100 text-slate-600' : ($shiftName === 'Pagi' ? 'bg-emerald-50 text-emerald-800' : ($shiftName === 'Sore' ? 'bg-amber-50 text-amber-800' : 'bg-indigo-50 text-indigo-800')) }}">{{ $shiftName }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">Pagi 08.00-16.00 - Sore 16.00-00.00 - Malam 00.00-08.00 WIB. Shift Malam setelah tengah malam masih milik tanggal operasional sebelumnya. Belum terhubung ke penugasan otomatis.</p>
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel title="Penyulang terkait">
            @if ($load->failed('penyulangs'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($penyulangs as $penyulang)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-semibold text-slate-800">{{ $penyulang->nama_penyulang }} <span class="text-[10px] font-normal text-slate-500">{{ $penyulang->kode_penyulang }}</span></span>
                            <a href="{{ route('admin.master-data.penyulang.show', $penyulang) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">Belum ada Penyulang terhubung.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
        <x-admin.panel title="Akun tim lapangan">
            @if ($load->failed('teams'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($teams as $team)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-semibold text-slate-800">{{ $team->name }} <span class="text-[10px] font-normal text-slate-500">{{ $team->code }} · {{ $team->role }}</span></span>
                            <span class="text-xs text-slate-500">{{ $team->active_personnel_count }} petugas aktif</span>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">Belum ada akun tim untuk Posko ini.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
    </div>
</div>
@endsection
