@extends('layouts.admin')

@section('title', 'Master Data')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $tabs = \App\Http\Controllers\Admin\MasterDataController::TABS;
    $statuses = \App\Http\Controllers\Admin\MasterDataController::STATUSES;
    $roles = \App\Http\Controllers\Admin\MasterDataController::TEAM_ROLES;
    $formatTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Data & Admin"
        title="Master Data"
        state="live"
        description="Tim lapangan, petugas, kode referensi, dan jadwal shift dikelola di sini (tercatat di Audit Log). Posko, Penyulang, dan Keypoint adalah master resmi hasil sinkronisasi sehingga baca saja."
    >
        <x-slot:actions>
            @if (array_key_exists($tab, $manageable))
                <x-admin.button :href="route('admin.master-data.manage.create', $manageable[$tab])">Tambah {{ \Illuminate\Support\Str::lower($tabs[$tab]) }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['posko', 'penyulang', 'tim', 'petugas', 'kode', 'keypoint'] as $countKey)
            <x-admin.stat-card :label="$tabs[$countKey]" :value="$counts[$countKey]" :href="route('admin.master-data.index', ['tab' => $countKey])" unavailable-label="Gagal dimuat" />
        @endforeach
    </div>

    <x-admin.panel flush>
        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3" aria-label="Tab master data">
            @foreach ($tabs as $tabKey => $tabLabel)
                <a href="{{ route('admin.master-data.index', ['tab' => $tabKey]) }}" data-loading-link class="whitespace-nowrap rounded-t-md border-b-2 px-3 py-2 text-sm font-semibold {{ $tab === $tabKey ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}" @if ($tab === $tabKey) aria-current="page" @endif>{{ $tabLabel }}</a>
            @endforeach
        </nav>

        @if (in_array($tab, ['posko', 'penyulang', 'keypoint'], true))
            <p class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-[11px] text-slate-600">Master resmi: {{ $tab === 'keypoint' ? 'diisi keypoint:sync dari Google Sheets' : '6 Posko dan 16 Penyulang resmi diisi topology:sync-core-masters' }}; tidak diubah dari Web agar tidak bertentangan dengan sinkronisasi.</p>
        @endif

        @if ($tab !== 'sumber')
            <x-admin.filter-bar :action="route('admin.master-data.index')" :reset-url="route('admin.master-data.index', ['tab' => $tab])" :active="$hasFilter">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <x-admin.filter-input name="q" label="Cari" :value="$filters['q']" placeholder="Nama atau kode" />
                @if (in_array($tab, ['tim', 'petugas', 'jadwal'], true))
                    <x-admin.filter-select name="role" label="Role tim" :options="$roles" :value="$filters['role']" placeholder="Semua role" />
                @endif
                @if ($tab === 'kode')
                    <x-admin.filter-select name="category" label="Kategori" :options="$options['category'] ?? []" :value="$filters['category']" placeholder="Semua kategori" />
                @endif
                @if ($tab === 'keypoint')
                    <x-admin.filter-select name="penyulang" label="Penyulang (sumber)" :options="$options['penyulang'] ?? []" :value="$filters['penyulang']" placeholder="Semua penyulang" />
                    <x-admin.filter-select name="jenis" label="Jenis" :options="$options['jenis'] ?? []" :value="$filters['jenis']" placeholder="Semua jenis" />
                @else
                    <x-admin.filter-select name="status" label="Status" :options="$statuses" :value="$filters['status']" placeholder="Semua status" />
                @endif
            </x-admin.filter-bar>
        @endif

        @if ($tab === 'sumber')
            <x-admin.table min-width="min-w-[860px]">
                <x-slot:head>
                    <th class="px-4 py-3">Sumber</th>
                    <th class="px-4 py-3">Jenis</th>
                    <th class="px-4 py-3 text-right">Baris</th>
                    <th class="px-4 py-3">Terakhir impor/sinkron</th>
                    <th class="px-4 py-3">Catatan</th>
                </x-slot:head>
                @foreach ($sources as $source)
                    <tr class="align-top">
                        <td class="px-4 py-3"><div class="font-semibold text-slate-900">{{ $source['label'] }}</div><div class="font-mono text-[10px] text-slate-500">{{ $source['table'] }}</div></td>
                        <td class="px-4 py-3"><x-admin.status-badge :tone="$source['kind'] === 'Staging mentah' ? 'slate' : 'blue'">{{ $source['kind'] }}</x-admin.status-badge></td>
                        @if ($load->failed($source['key']))
                            <td colspan="2" class="px-4 py-3"><span class="text-xs font-semibold text-red-700" role="alert">Gagal dimuat (tabel tidak tersedia atau koneksi gagal)</span></td>
                        @else
                            <td class="px-4 py-3 text-right"><div class="font-bold text-slate-800">{{ number_format($source['total'], 0, ',', '.') }}</div>@if ($source['extra'])<div class="text-[10px] text-slate-500">{{ $source['extra'] }}</div>@endif</td>
                            <td class="px-4 py-3 text-xs">{{ $formatTime($source['last_at']) }}</td>
                        @endif
                        <td class="max-w-sm px-4 py-3 text-[11px] text-slate-500">{{ $source['description'] }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
            <p class="border-t border-slate-200 p-4 text-[10px] text-slate-500">Staging mentah dipertahankan untuk provenance dan tidak dijadikan master operasional. Angka di atas adalah jumlah baris aktual pada database yang terhubung.</p>
        @elseif ($load->failed('rows'))
            <x-admin.error-state />
        @else
            @switch($tab)
                @case('posko')
                    <x-admin.table min-width="min-w-[720px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Posko</th>
                            <th class="px-4 py-3 text-right">Penyulang terkait</th>
                            <th class="px-4 py-3 text-right">Akun tim</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $posko)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3"><div class="font-semibold text-slate-900">{{ $posko->nama_posko }}</div><div class="text-[10px] text-slate-500">{{ $posko->kode_posko }}</div></td>
                                <td class="px-4 py-3 text-right">{{ $posko->penyulangs_count }}</td>
                                <td class="px-4 py-3 text-right">{{ $teamCounts[$posko->id] ?? 0 }}</td>
                                <td class="px-4 py-3"><x-admin.status-badge :tone="$posko->is_active ? 'emerald' : 'slate'">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.posko.show', $posko) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-admin.empty-state title="Belum ada Posko." description="Master Posko kosong atau tidak cocok dengan filter. Jalankan sinkron topologi untuk mengisi master." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('penyulang')
                    <x-admin.table min-width="min-w-[720px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Penyulang</th>
                            <th class="px-4 py-3">Gardu induk</th>
                            <th class="px-4 py-3 text-right">Posko terkait</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $penyulang)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3"><div class="font-semibold text-slate-900">{{ $penyulang->nama_penyulang }}</div><div class="text-[10px] text-slate-500">{{ $penyulang->kode_penyulang }}</div></td>
                                <td class="px-4 py-3">{{ $penyulang->gardu_induk ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">{{ $penyulang->poskos_count }}</td>
                                <td class="px-4 py-3"><x-admin.status-badge :tone="$penyulang->is_active ? 'emerald' : 'slate'">{{ $penyulang->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.penyulang.show', $penyulang) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-admin.empty-state title="Belum ada Penyulang." description="Master Penyulang kosong atau tidak cocok dengan filter." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('tim')
                    <x-admin.table min-width="min-w-[760px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Tim</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Posko</th>
                            <th class="px-4 py-3 text-right">Petugas aktif / total</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $team)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3"><div class="font-semibold text-slate-900">{{ $team->name }}</div><div class="text-[10px] text-slate-500">{{ $team->code }}</div></td>
                                <td class="px-4 py-3"><x-admin.status-badge tone="blue">{{ $team->role }}</x-admin.status-badge></td>
                                <td class="px-4 py-3">{{ $team->posko?->nama_posko ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">{{ $team->active_personnel_count }} / {{ $team->personnel_count }}</td>
                                <td class="px-4 py-3"><x-admin.status-badge :tone="$team->is_active ? 'emerald' : 'slate'">{{ $team->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.manage.edit', ['tim', $team->id]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Ubah</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-admin.empty-state title="Belum ada tim lapangan." description="Tambahkan tim POSKO/HARSUS/INSPEKSI/ROW dengan tombol Tambah." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('petugas')
                    <x-admin.table min-width="min-w-[720px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Petugas</th>
                            <th class="px-4 py-3">Kode</th>
                            <th class="px-4 py-3">Tim</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $person)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $person->name }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $person->personnel_code ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $person->team?->name ?? '-' }} <span class="text-[10px] text-slate-500">{{ $person->team?->role }}</span></td>
                                <td class="px-4 py-3"><x-admin.status-badge :tone="$person->is_active ? 'emerald' : 'slate'">{{ $person->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.manage.edit', ['petugas', $person->id]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Ubah</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-admin.empty-state title="Belum ada data petugas." description="Tambahkan petugas (orang pelaksana) per tim dengan tombol Tambah." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('kode')
                    <x-admin.table min-width="min-w-[760px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Kategori</th>
                            <th class="px-4 py-3">Kode</th>
                            <th class="px-4 py-3">Label</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $code)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 text-xs font-semibold text-slate-700">{{ $code->category }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $code->code }}</td>
                                <td class="px-4 py-3">{{ $code->label }}</td>
                                <td class="px-4 py-3 text-xs">{{ $code->role_scope ?? 'Semua' }}</td>
                                <td class="px-4 py-3"><x-admin.status-badge :tone="$code->is_active ? 'emerald' : 'slate'">{{ $code->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.manage.edit', ['kode', $code->id]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Ubah</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-admin.empty-state title="Belum ada kode referensi." description="Tambahkan kode dropdown: alasan reschedule, alasan limpahan, dan jenis temuan inspeksi." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('jadwal')
                    <div class="border-b border-slate-200 bg-blue-50/60 px-4 py-4">
                        <h3 class="text-sm font-bold text-slate-900">Rotasi 4 pasangan petugas POSKO</h3>
                        <p class="mt-1 text-xs leading-relaxed text-slate-600">Mulai 9 November 2026, tiap POSKO memakai 4 pasangan: Pagi, Sore, Malam, dan Libur. Pilih POSKO untuk melihat nama petugas serta pratinjau rotasi per tanggal. Jadwal tim induk di tabel lama di bawah <strong>bukan</strong> penugasan 4 pasangan.</p>
                        @if ($load->failed('roster_poskos'))
                            <x-admin.error-state :inset="false" />
                        @else
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($rosterPoskos as $rosterPosko)
                                    <a href="{{ route('admin.master-data.posko.show', $rosterPosko) }}" class="rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-800 hover:bg-blue-100">{{ $rosterPosko->nama_posko }} · Lihat rotasi</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <p class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-500">Data di bawah adalah jadwal manual tim induk (model lama), hanya untuk pemeriksaan. Penambahan jadwal lama disembunyikan agar tidak bentrok dengan rotasi pasangan.</p>
                    <x-admin.table min-width="min-w-[720px]">
                        <x-slot:head>
                            <th class="px-4 py-3">Tim POSKO</th>
                            <th class="px-4 py-3">Shift</th>
                            <th class="px-4 py-3">Berlaku mulai</th>
                            <th class="px-4 py-3">Sampai</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </x-slot:head>
                        @forelse ($rows as $assignment)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $assignment->team?->name ?? '-' }} <span class="text-[10px] text-slate-500">{{ $assignment->team?->posko?->nama_posko }}</span></td>
                                <td class="px-4 py-3"><x-admin.status-badge tone="blue">{{ ['A' => 'Pagi', 'B' => 'Sore', 'C' => 'Malam'][$assignment->shift_code] ?? $assignment->shift_code }}</x-admin.status-badge></td>
                                <td class="px-4 py-3 text-xs">{{ $assignment->effective_from?->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-xs">{{ $assignment->effective_until?->format('d/m/Y') ?? 'Tanpa batas' }}</td>
                                <td class="px-4 py-3 text-center"><a href="{{ route('admin.master-data.manage.edit', ['jadwal', $assignment->id]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Ubah</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-admin.empty-state title="Belum ada jadwal shift tim." description="Atur shift A/B/C per tim POSKO berikut periode berlakunya." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break

                @case('keypoint')
                    <x-admin.table min-width="min-w-[860px]">
                        <x-slot:head>
                            <th class="px-4 py-3">ID</th>
                            <th class="px-4 py-3">Nama</th>
                            <th class="px-4 py-3">Jenis</th>
                            <th class="px-4 py-3">Penyulang (sumber)</th>
                            <th class="px-4 py-3">Koordinat</th>
                            <th class="px-4 py-3">Sinkron terakhir</th>
                        </x-slot:head>
                        @forelse ($rows as $keypoint)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-mono text-xs">{{ $keypoint->id }}</td>
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $keypoint->nama ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ $keypoint->jenis ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ $keypoint->penyulang ?? '-' }}</td>
                                <td class="px-4 py-3 font-mono text-[11px]">
                                    @if ($keypoint->latitude !== null && $keypoint->longitude !== null)
                                        {{ $keypoint->latitude }}, {{ $keypoint->longitude }}
                                    @else
                                        <span class="font-sans text-amber-700">Belum ada koordinat</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">{{ $formatTime($keypoint->last_synced_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-admin.empty-state title="Belum ada Keypoint." description="Tabel keypoints kosong atau tidak cocok dengan filter. Data diisi oleh keypoint:sync." /></td></tr>
                        @endforelse
                    </x-admin.table>
                    @break
            @endswitch
            <x-admin.pagination :paginator="$rows" :label="'data '.\Illuminate\Support\Str::lower($tabs[$tab])" />
        @endif
    </x-admin.panel>
</div>
@endsection
