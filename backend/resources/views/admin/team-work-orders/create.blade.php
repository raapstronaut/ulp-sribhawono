@extends('layouts.admin')

@php
    use App\Http\Controllers\Admin\TeamWorkOrderController;
    $isHarsus = $teamType === \App\Models\WorkOrder::TEAM_HARSUS;
    $title = $isHarsus ? 'Buat WO HARSUS' : 'Buat WO ROW';
    $targetWord = $isHarsus ? 'aset jaringan' : 'titik vegetasi';
@endphp

@section('title', $title)

@section('content')
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" :parent="$isHarsus ? 'HARSUS' : 'ROW'" :parent-url="route($isHarsus ? 'admin.harsus.index' : 'admin.row.index')" :title="$title"
        :description="$isHarsus
            ? 'WO PEKERJAAN_KHUSUS manual wajib menunjuk aset jaringan (keypoint hasil sinkronisasi). Satu aset hanya boleh punya satu WO HARSUS aktif.'
            : 'WO RABAS/PANGKAS/TEBANG manual menunjuk titik vegetasi dari data sumber (staging). Satu titik hanya boleh punya satu WO aktif per jenis.'" />

    <x-admin.panel flush title="1. Pilih penyulang dan {{ $targetWord }}" description="Lokasi tidak ditebak: hanya {{ $targetWord }} pada penyulang yang dipilih yang dapat menjadi target.">
        <x-admin.filter-bar :action="route('admin.team-work-orders.create', $routeTeam)" :reset-url="route('admin.team-work-orders.create', $routeTeam)" :active="$filters['penyulang_id'] !== '' || $filters['q'] !== ''">
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$filters['penyulang_id']" placeholder="Pilih penyulang" />
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" :placeholder="$isHarsus ? 'Nama atau jenis aset' : 'ID pohon, nama pohon, atau tiang'" />
        </x-admin.filter-bar>

        @if ($penyulang === null)
            <x-admin.empty-state title="Pilih penyulang terlebih dahulu." :description="'Daftar '.$targetWord.' dimuat setelah penyulang dipilih.'" />
        @elseif ($candidates->total() === 0)
            <x-admin.empty-state :title="'Tidak ada '.$targetWord.' pada penyulang ini.'" :description="$isHarsus ? 'Data keypoint berasal dari sinkronisasi KEYPOINT. Periksa penulisan penyulang di sumber atau kata pencarian.' : 'Data vegetasi berasal dari staging sumber. Periksa kata pencarian atau penulisan penyulang di sumber.'" />
        @else
            <x-admin.table>
                <x-slot:head>
                    @if ($isHarsus)
                        <th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Nama aset</th><th class="px-4 py-3">Koordinat</th><th class="px-4 py-3">Status WO</th><th class="px-4 py-3"></th>
                    @else
                        <th class="px-4 py-3">ID pohon</th><th class="px-4 py-3">Pohon / tiang</th><th class="px-4 py-3">Kondisi · jarak</th><th class="px-4 py-3">Perkiraan bahaya</th><th class="px-4 py-3">Status WO</th><th class="px-4 py-3"></th>
                    @endif
                </x-slot:head>
                @foreach ($candidates as $row)
                    @php
                        $key = TeamWorkOrderController::targetKeyOf($teamType, $row);
                        $coords = TeamWorkOrderController::coordinatesOf($row);
                        $isSelected = $selected && $selected->id === $row->id;
                    @endphp
                    <tr @class(['bg-blue-50' => $isSelected])>
                        @if ($isHarsus)
                            <td class="px-4 py-2 text-xs font-semibold">{{ $row->jenis }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row->nama }}</td>
                        @else
                            <td class="px-4 py-2 text-xs font-semibold">{{ $row->id_pohon_raw ?? '-' }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row->nama_pohon_raw ?? '-' }} · {{ $row->nama_tiang_raw ?? '-' }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row->kondisi_raw ?? '-' }} · {{ $row->jarak_pohon_saat_ini_raw ?? '-' }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row->tanggal_perkiraan_bahaya_raw ?? '-' }}</td>
                        @endif
                        @if ($isHarsus)
                            <td class="px-4 py-2 text-xs">{{ $coords ? $coords[0].', '.$coords[1] : 'Tidak ada' }}</td>
                        @endif
                        <td class="px-4 py-2 text-xs">
                            @if ($key !== null && isset($activeKeys[$key]))
                                <x-admin.status-badge tone="amber">WO aktif {{ $activeKeys[$key] }}</x-admin.status-badge>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ request()->fullUrlWithQuery(['target_id' => $row->id]) }}#form" class="text-xs font-semibold text-blue-600 hover:text-blue-800">{{ $isSelected ? 'Terpilih' : 'Pilih' }}</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$candidates" :label="$targetWord" />
        @endif
    </x-admin.panel>

    <x-admin.panel title="2. Penugasan" id="form">
        @if ($selected === null)
            <x-admin.empty-state :title="'Belum ada '.$targetWord.' terpilih.'" description="Pilih salah satu baris pada daftar di atas." />
        @else
            @php $coords = TeamWorkOrderController::coordinatesOf($selected); @endphp
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
                Target: <strong>{{ $isHarsus ? ($selected->jenis.' · '.$selected->nama) : (($selected->id_pohon_raw ?? '-').' · '.($selected->nama_pohon_raw ?? '-')) }}</strong>
                · Penyulang {{ $penyulang->nama_penyulang }}
                · Koordinat {{ $coords ? $coords[0].', '.$coords[1] : 'tidak tersedia' }}
            </div>
            <form method="POST" action="{{ route('admin.team-work-orders.store', $routeTeam) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="penyulang_id" value="{{ $penyulang->id }}">
                <input type="hidden" name="target_id" value="{{ $selected->id }}">
                @unless ($isHarsus)
                    <input type="hidden" name="target_ref" value="{{ $selected->id_pohon_raw }}">
                @endunless
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-admin.select name="work_type" label="Jenis pekerjaan" :options="$workTypeOptions" :placeholder="count($workTypeOptions) > 1 ? 'Pilih jenis' : null" required />
                    <x-admin.select name="field_team_id" label="Tim pelaksana" :options="$teamOptions" placeholder="Semua tim {{ $teamType }}" :hint="$teamOptions === [] ? 'Belum ada tim '.$teamType.' aktif di Master Data.' : null" />
                    <x-admin.select name="posko_id" label="POSKO terkait (opsional)" :options="$poskoOptions" placeholder="Tidak ada" />
                    <x-admin.input type="date" name="work_date" label="Tanggal kerja" :value="$today" required />
                    <x-admin.input type="date" name="due_date" label="Target selesai" :value="$today" required hint="Batas waktu pukul 23:59 WIB." />
                    <x-admin.input name="section_name" label="Section" maxlength="255" />
                </div>
                <x-admin.textarea name="notes" label="Catatan / uraian pekerjaan" rows="3" />
                <div class="flex flex-wrap gap-3">
                    <x-admin.button>Buat WO</x-admin.button>
                    <x-admin.button variant="secondary" :href="route($isHarsus ? 'admin.harsus.index' : 'admin.row.index')">Batal</x-admin.button>
                </div>
            </form>
        @endif
    </x-admin.panel>
</div>
@endsection
