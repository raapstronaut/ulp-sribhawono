@extends('layouts.admin')

@section('title', 'Inspeksi')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="Inspeksi"
        state="skeleton"
        description="Area rekomendasi berdasarkan skor prioritas dan rotasi, hasil kunjungan termasuk Tidak Ada Temuan, serta temuan yang menunggu validasi Admin."
    />

    <x-admin.notice tone="warning" title="Domain Inspeksi belum dibangun:">
        Belum ada tabel kunjungan maupun temuan inspeksi, dan aplikasi Android tim INSPEKSI juga belum mengirim data. Target minimal satu temuan nyata per hari hanya ditampilkan sebagai target. Hasil "Tidak Ada Temuan" tetap sah dicatat dan tidak dihitung sebagai temuan.
    </x-admin.notice>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat-card label="Tim INSPEKSI" :value="$load->failed('teams') ? null : $teams->where('is_active', true)->count()" hint="Akun tim aktif pada master field_teams" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Kunjungan hari ini" hint="Domain kunjungan belum tersedia" />
        <x-admin.stat-card label="Temuan nyata hari ini" hint="Target ≥ 1 temuan nyata per hari" />
        <x-admin.stat-card label="Menunggu validasi" hint="Antrean ACC/Tolak Admin" :href="route('admin.reviews.index')" />
    </div>

    <x-admin.panel flush title="Temuan Menunggu Validasi" description="Sistem merekomendasikan tim penerima menurut klasifikasi; Admin memutuskan ACC/Tolak sebelum temuan memutakhirkan master aset.">
        <x-admin.filter-bar :action="route('admin.inspeksi.index')" disabled note="Filter aktif setelah data kunjungan dan temuan tersedia.">
            <x-admin.filter-input type="date" name="visit_date" label="Tanggal kunjungan" disabled />
            <x-admin.filter-select name="validation_status" label="Status validasi" disabled placeholder="Semua status" />
            <x-admin.filter-select name="section" label="Section" disabled placeholder="Semua Section" />
        </x-admin.filter-bar>
        <x-admin.table min-width="min-w-[860px]">
            <x-slot:head>
                <th class="px-4 py-3">Temuan</th>
                <th class="px-4 py-3">Section / Aset</th>
                <th class="px-4 py-3">Klasifikasi</th>
                <th class="px-4 py-3">Rekomendasi tim</th>
                <th class="px-4 py-3">Waktu</th>
                <th class="px-4 py-3 text-center">Keputusan</th>
            </x-slot:head>
            <tr>
                <td colspan="6">
                    <x-admin.empty-state title="Belum ada temuan inspeksi." description="Temuan akan muncul setelah tim INSPEKSI mengirim laporan melalui Android dan tabel inspection_findings tersedia.">
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            <x-admin.unavailable-action label="ACC" variant="secondary" size="sm" reason="Validasi temuan membutuhkan tabel temuan, rekomendasi tim, dan workflow approval beraudit." />
                            <x-admin.unavailable-action label="Tolak" variant="secondary" size="sm" reason="Penolakan temuan membutuhkan workflow approval beraudit." />
                        </div>
                    </x-admin.empty-state>
                </td>
            </tr>
        </x-admin.table>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Area Rekomendasi & Rotasi"
            description="Sistem mengarahkan tim ke Section berisiko tanpa terus mengulang area yang sama. Setelah hasil Tidak Ada Temuan, tim diarahkan ke area ranking berikutnya."
            :requirements="['Section canonical tervalidasi', 'Formula skor prioritas dan rotasi (TBD)', 'Riwayat kunjungan per Section']"
        />
        <x-admin.unavailable-panel
            title="Hasil Kunjungan"
            description="Setiap kunjungan mencatat ada atau tidaknya temuan. Kunjungan tanpa temuan tersimpan apa adanya sebagai catatan jujur."
            :requirements="['Tabel inspections dengan has_finding / no_finding_record', 'API mobile tim INSPEKSI']"
        />
    </div>

    <x-admin.panel title="Tim INSPEKSI pada master" description="Akun tim terpisah dari petugas; petugas aktual dipilih saat melapor.">
        <x-slot:actions>
            <a href="{{ route('admin.master-data.index', ['tab' => 'tim', 'role' => 'INSPEKSI']) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Buka Master Data →</a>
        </x-slot:actions>
        @if ($load->failed('teams'))
            <x-admin.error-state :inset="false" />
        @else
            <ul class="space-y-2 text-sm">
                @forelse ($teams as $team)
                    <li class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-slate-800">{{ $team->name }} <span class="text-[10px] font-normal text-slate-500">{{ $team->code }}</span></span>
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            {{ $team->personnel_count }} petugas aktif
                            <x-admin.status-badge :tone="$team->is_active ? 'emerald' : 'slate'">{{ $team->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.status-badge>
                        </span>
                    </li>
                @empty
                    <li class="text-xs text-slate-500">Belum ada akun tim INSPEKSI pada master field_teams.</li>
                @endforelse
            </ul>
        @endif
    </x-admin.panel>
</div>
@endsection
