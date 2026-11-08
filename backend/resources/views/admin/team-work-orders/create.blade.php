@extends('layouts.admin')

@php
    use App\Http\Controllers\Admin\TeamWorkOrderController;
    $isHarsus = $teamType === \App\Models\WorkOrder::TEAM_HARSUS;
    $title = $isHarsus ? 'Buat WO HARSUS' : 'Buat WO ROW';
    $targetWord = $isHarsus ? 'aset jaringan' : 'titik vegetasi';
    $backRoute = $isHarsus ? 'admin.harsus.index' : 'admin.row.index';
@endphp

@section('title', $title)

@section('content')
<div class="mx-auto max-w-7xl space-y-5 pb-12">
    <x-admin.page-header section="Pekerjaan" :parent="$isHarsus ? 'HARSUS' : 'ROW'" :parent-url="route($backRoute)" :title="$title" state="partial"
        :description="$isHarsus ? 'Pilih aset jaringan dari penyulang yang benar, lalu tetapkan jenis pekerjaan dan jadwal.' : 'Pilih titik vegetasi dari penyulang yang benar, lalu tentukan RABAS, PANGKAS, atau TEBANG dan jadwal pekerjaan.'">
        <x-slot:actions>
            <x-admin.button variant="secondary" :href="route($backRoute)">Kembali ke daftar WO</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <section class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm sm:p-5" aria-label="Alur pembuatan pekerjaan">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">WO manual</span>
                    <span class="rounded-md border border-blue-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-slate-700">{{ $isHarsus ? 'HARSUS' : 'ROW' }}</span>
                    @if ($selected)
                        <span class="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">Target dipilih</span>
                    @else
                        <span class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-700">Menunggu target</span>
                    @endif
                </div>
                <h2 class="mt-3 text-lg font-bold text-slate-900">Terbitkan pekerjaan dari lokasi yang jelas</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                    Cari {{ $targetWord }} di penyulang yang dipilih, periksa data target, kemudian isi penugasan.
                    {{ $isHarsus ? 'WO HARSUS hanya boleh menunjuk satu aset jaringan yang sah.' : 'Target vegetasi berasal dari data staging dan belum menjadi master vegetasi canonical.' }}
                </p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:w-[370px] lg:shrink-0">
                <a href="#daftar-target" class="rounded-lg border border-blue-100 bg-white p-3 hover:border-blue-300">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700">Langkah 1</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900">Pilih lokasi target</div>
                    <div class="mt-1 text-[11px] text-slate-500">Penyulang, pencarian, dan data sumber</div>
                </a>
                <a href="{{ $selected ? '#form' : '#daftar-target' }}" class="rounded-lg border {{ $selected ? 'border-blue-200 bg-white' : 'border-slate-200 bg-slate-50' }} p-3 hover:border-blue-300">
                    <div class="text-[10px] font-bold uppercase tracking-wide {{ $selected ? 'text-blue-700' : 'text-slate-500' }}">Langkah 2</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900">Atur penugasan</div>
                    <div class="mt-1 text-[11px] text-slate-500">Jenis, tim, tanggal, dan catatan</div>
                </a>
            </div>
        </div>
    </section>

    @if ($teamOptions === [])
        <div role="status" class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-xs leading-5 text-amber-900">
            <strong>Tim {{ $teamType }} aktif belum tersedia di Master Data.</strong>
            Pilihan tim pelaksana masih kosong. Field tim saat ini bersifat opsional, tetapi pekerjaan yang dibuat tanpa tim belum memiliki penanggung jawab tim yang ditetapkan. Lengkapi master tim sebelum digunakan secara operasional.
        </div>
    @endif

    <div id="daftar-target" class="scroll-mt-24"></div>
    <x-admin.panel flush title="1. Pilih penyulang dan {{ $targetWord }}" description="Lokasi tidak ditebak: hanya {{ $targetWord }} pada penyulang yang dipilih yang dapat menjadi target.">
        <x-admin.filter-bar :action="route('admin.team-work-orders.create', $routeTeam)" :reset-url="route('admin.team-work-orders.create', $routeTeam)" :active="$filters['penyulang_id'] !== '' || $filters['q'] !== ''">
            <x-admin.filter-select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" :value="$filters['penyulang_id']" placeholder="Pilih penyulang" />
            <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" :placeholder="$isHarsus ? 'Nama atau jenis aset' : 'ID pohon, nama pohon, atau tiang'" />
        </x-admin.filter-bar>

        @if ($penyulang !== null)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-xs text-slate-600">
                <span><strong class="text-slate-900">{{ $candidates->total() }}</strong> {{ $targetWord }} ditemukan di <strong class="text-slate-900">{{ $penyulang->nama_penyulang }}</strong>.</span>
                @if ($selected)
                    <a href="#form" class="font-semibold text-blue-700 hover:text-blue-900">Lanjut ke penugasan</a>
                @else
                    <span>Pilih satu baris untuk melanjutkan.</span>
                @endif
            </div>
        @endif

        @if ($penyulang === null)
            <x-admin.empty-state title="Pilih penyulang terlebih dahulu." :description="'Daftar '.$targetWord.' dimuat setelah penyulang dipilih.'" />
        @elseif ($candidates->total() === 0)
            <x-admin.empty-state :title="'Tidak ada '.$targetWord.' pada penyulang ini.'" :description="$isHarsus ? 'Data keypoint berasal dari sinkronisasi KEYPOINT. Periksa penyulang atau kata pencarian.' : 'Data vegetasi berasal dari staging sumber. Periksa penyulang atau kata pencarian.'" />
        @else
            <x-admin.table min-width="min-w-[900px]">
                <x-slot:head>
                    @if ($isHarsus)
                        <th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Nama aset</th><th class="px-4 py-3">Koordinat</th><th class="px-4 py-3">Status WO</th><th class="px-4 py-3 text-right">Aksi</th>
                    @else
                        <th class="px-4 py-3">ID pohon</th><th class="px-4 py-3">Pohon / tiang</th><th class="px-4 py-3">Kondisi · jarak</th><th class="px-4 py-3">Tanggal perkiraan bahaya</th><th class="px-4 py-3">Status WO</th><th class="px-4 py-3 text-right">Aksi</th>
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
                            <td class="px-4 py-3 text-xs font-semibold">{{ $row->jenis }}</td>
                            <td class="px-4 py-3 text-xs">{{ $row->nama }}</td>
                        @else
                            <td class="px-4 py-3 text-xs font-semibold text-slate-900">{{ $row->id_pohon_raw ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $row->nama_pohon_raw ?? '-' }} · {{ $row->nama_tiang_raw ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $row->kondisi_raw ?? '-' }} · {{ $row->jarak_pohon_saat_ini_raw ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $row->tanggal_perkiraan_bahaya_raw ?? '-' }}</td>
                        @endif
                        @if ($isHarsus)
                            <td class="px-4 py-3 text-xs">{{ $coords ? $coords[0].', '.$coords[1] : 'Tidak ada' }}</td>
                        @endif
                        <td class="px-4 py-3 text-xs">
                            @if ($key !== null && isset($activeKeys[$key]))
                                <x-admin.status-badge tone="amber">WO aktif {{ $activeKeys[$key] }}</x-admin.status-badge>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ request()->fullUrlWithQuery(['target_id' => $row->id]) }}#form" class="inline-flex rounded-md border px-3 py-1.5 text-xs font-semibold {{ $isSelected ? 'border-blue-300 bg-blue-100 text-blue-800' : 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100' }}">{{ $isSelected ? 'Terpilih' : 'Pilih target' }}</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$candidates" :label="$targetWord" />
        @endif
    </x-admin.panel>

    <div id="form" class="scroll-mt-24"></div>
    <x-admin.panel title="2. Atur penugasan" description="Periksa target dan lengkapi informasi pekerjaan sebelum menerbitkan WO.">
        @if ($selected === null)
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                Pilih {{ $targetWord }} pada daftar di atas terlebih dahulu. Setelah target dipilih, formulir penugasan akan tersedia.
            </div>
        @else
            @php $coords = TeamWorkOrderController::coordinatesOf($selected); @endphp
            <div class="mb-5 rounded-xl border border-blue-200 bg-blue-50/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700">Target terpilih</div>
                        <div class="mt-1 text-sm font-bold text-slate-900">{{ $isHarsus ? ($selected->jenis.' · '.$selected->nama) : (($selected->id_pohon_raw ?? '-').' · '.($selected->nama_pohon_raw ?? '-')) }}</div>
                        <div class="mt-1 text-xs text-slate-600">Penyulang {{ $penyulang->nama_penyulang }}</div>
                    </div>
                    <a href="#daftar-target" class="rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">Lihat / ganti target</a>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-2 border-t border-blue-100 pt-3 text-xs text-slate-700 sm:grid-cols-2">
                    <div><span class="font-semibold">Koordinat:</span> {{ $coords ? $coords[0].', '.$coords[1] : 'Tidak tersedia' }}</div>
                    @if ($isHarsus)
                        <div><span class="font-semibold">Sumber:</span> Data KEYPOINT</div>
                    @else
                        <div><span class="font-semibold">Tiang:</span> {{ $selected->nama_tiang_raw ?? '-' }}</div>
                        <div><span class="font-semibold">Kondisi / jarak:</span> {{ $selected->kondisi_raw ?? '-' }} / {{ $selected->jarak_pohon_saat_ini_raw ?? '-' }}</div>
                        <div><span class="font-semibold">Tanggal perkiraan bahaya (sumber):</span> {{ $selected->tanggal_perkiraan_bahaya_raw ?? '-' }}</div>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('admin.team-work-orders.store', $routeTeam) }}" class="space-y-5">
                @csrf
                <input type="hidden" name="penyulang_id" value="{{ $penyulang->id }}">
                <input type="hidden" name="target_id" value="{{ $selected->id }}">
                @unless ($isHarsus)
                    <input type="hidden" name="target_ref" value="{{ $selected->id_pohon_raw }}">
                @endunless

                <div>
                    <h3 class="mb-3 text-sm font-bold text-slate-900">Informasi pekerjaan dan pelaksana</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-admin.select name="work_type" label="Jenis pekerjaan" :options="$workTypeOptions" :placeholder="count($workTypeOptions) > 1 ? 'Pilih jenis' : null" required />
                        <x-admin.select name="field_team_id" label="Tim pelaksana" :options="$teamOptions" placeholder="Belum ditentukan" :hint="$teamOptions === [] ? 'Belum ada tim '.$teamType.' aktif di Master Data.' : 'Pilih tim yang akan menerima tugas bila sudah ditentukan.'" />
                        <x-admin.select name="posko_id" label="POSKO terkait (opsional)" :options="$poskoOptions" placeholder="Tidak ada" />
                        <x-admin.input type="date" name="work_date" label="Tanggal kerja" :value="$today" required />
                        <x-admin.input type="date" name="due_date" label="Target selesai" :value="$today" required hint="Batas waktu pukul 23:59 WIB." />
                        <x-admin.input name="section_name" label="Section (opsional)" maxlength="255" />
                    </div>
                </div>
                <x-admin.textarea name="notes" label="Catatan / uraian pekerjaan" rows="3" />
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                    <strong class="text-slate-800">Periksa sebelum membuat WO:</strong> target, jenis pekerjaan, tanggal, tim pelaksana, serta POSKO terkait sudah sesuai. Memilih POSKO tidak otomatis menetapkan tim. {{ $isHarsus ? 'Target HARSUS berasal dari data keypoint yang tersinkron.' : 'Target ROW masih berasal dari data vegetasi staging.' }}
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-admin.button>Buat WO</x-admin.button>
                    <x-admin.button variant="secondary" :href="route($backRoute)">Batal</x-admin.button>
                </div>
            </form>
        @endif
    </x-admin.panel>
</div>
@endsection
