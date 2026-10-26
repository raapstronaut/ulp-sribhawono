@extends('layouts.admin')

@section('title', 'PASILA '.$posko->nama_posko)

@section('content')
@php
    $workDateLabel = \Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y');
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        parent="PASILA"
        :parent-url="route('admin.pasila.index', $filters)"
        :title="$posko->nama_posko"
        state="partial"
        :description="'Konteks shift '.$context['shift']['code'].' ('.$context['shift']['name'].', '.$context['shift']['time'].') tanggal '.$workDateLabel.'. Sesi PASILA belum tersedia; data WO APKUK berasal dari database.'"
    >
        <x-slot:actions>
            <x-admin.unavailable-action label="Buat P0 dari PASILA" reason="P0 wajib dibuat dari sesi PASILA yang valid pada Section dan Posko yang sama; sesi PASILA belum tersedia." />
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.pasila.show', $posko)" :reset-url="route('admin.pasila.show', $posko)" :active="request()->hasAny(['work_date', 'shift_code'])">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal kerja" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
        </x-admin.filter-bar>
        <dl class="grid grid-cols-2 gap-5 p-4 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode Posko">{{ $posko->kode_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Status master">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="Shift">{{ 'Shift '.$context['shift']['code'].' · '.$context['shift']['name'] }}{{ $context['is_current'] ? ' (berjalan)' : '' }}</x-admin.detail-item>
            <x-admin.detail-item label="Target WO APKUK">{{ $targetPerShift }} pekerjaan per shift</x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel title="Penyulang terkait" description="Relasi posko_penyulang (many-to-many); bukan dasar kepemilikan aset atau Section.">
            @if ($load->failed('penyulangs'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="flex flex-wrap gap-2">
                    @forelse ($penyulangs as $penyulang)
                        <li><a href="{{ route('admin.master-data.penyulang.show', $penyulang) }}" class="inline-flex rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700">{{ $penyulang->nama_penyulang }}</a></li>
                    @empty
                        <li class="text-xs text-slate-500">Belum ada Penyulang terhubung pada master.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
        <x-admin.panel title="Tim POSKO" description="Akun tim lapangan pada master field_teams; petugas aktual dipilih saat melapor.">
            @if ($load->failed('teams'))
                <x-admin.error-state :inset="false" />
            @else
                <ul class="space-y-2 text-sm">
                    @forelse ($fieldTeams as $team)
                        <li class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-slate-800">{{ $team->name }} <span class="text-[10px] font-normal text-slate-500">{{ $team->code }} · {{ $team->role }}</span></span>
                            <span class="text-xs text-slate-500">{{ $team->personnel_count }} petugas aktif</span>
                        </li>
                    @empty
                        <li class="text-xs text-slate-500">Belum ada akun tim untuk Posko ini pada master field_teams.</li>
                    @endforelse
                </ul>
            @endif
        </x-admin.panel>
    </div>

    <x-admin.panel flush title="WO APKUK pada shift ini" description="Enam WO adalah target pekerjaan per shift; jumlah APKUK fisik dicatat terpisah. WO tersisa boleh di-reschedule tanpa mengunci PASILA + P0.">
        @if ($load->failed('work_orders'))
            <x-admin.error-state />
        @else
            <x-admin.work-order-table
                :work-orders="$workOrders"
                empty-title="Belum ada WO APKUK pada shift ini."
                empty-description="Belum ada WO APKUK untuk Posko, tanggal, dan shift yang dipilih."
            />
            <x-admin.pagination :paginator="$workOrders" label="WO APKUK" />
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="Pengajuan reschedule" description="Alasan dropdown + catatan manual wajib. Kebijakan approval reschedule per jenis pekerjaan masih TBD.">
        @if ($load->failed('reschedules'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[720px]">
                <x-slot:head>
                    <th class="px-4 py-3">WO</th>
                    <th class="px-4 py-3">Alasan</th>
                    <th class="px-4 py-3">Jadwal</th>
                    <th class="px-4 py-3">Status</th>
                </x-slot:head>
                @forelse ($reschedules as $reschedule)
                    <tr>
                        <td class="px-4 py-3"><a href="{{ route('admin.work-orders.show', $reschedule->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $reschedule->workOrder->code }}</a></td>
                        <td class="px-4 py-3"><div class="text-xs font-semibold text-slate-800">{{ $reschedule->reason_code }}</div><div class="text-[11px] text-slate-500">{{ $reschedule->note }}</div></td>
                        <td class="px-4 py-3 text-xs">{{ $reschedule->from_work_date?->format('d/m/Y') }} {{ $reschedule->from_shift_code }} → {{ $reschedule->proposed_work_date?->format('d/m/Y') }} {{ $reschedule->proposed_shift_code }}</td>
                        <td class="px-4 py-3"><x-admin.status-badge :tone="$reschedule->status === \App\Models\WorkOrderReschedule::STATUS_MENUNGGU_ACC ? 'amber' : 'slate'">{{ $reschedule->status }}</x-admin.status-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-admin.empty-state title="Belum ada pengajuan reschedule." description="Tidak ada reschedule untuk WO APKUK pada shift ini." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Sesi PASILA & Section"
            description="Sesi PASILA shift ini belum ada karena generator awal shift dan tabel sesi belum dibangun. Section tidak ditebak dari relasi Penyulang."
            :requirements="['Tabel shift_instances dan pasila_sessions', 'Section canonical dengan kepemilikan Posko tervalidasi']"
        />
        <x-admin.unavailable-panel
            title="Temuan per Titik"
            description="Daftar kartu temuan per titik (jenis/kode, aset/koordinat, foto, GPS, petugas, waktu) akan tampil di sini."
            :requirements="['Tabel pasila_findings dan media temuan', 'Kode temuan resmi']"
        />
        <x-admin.unavailable-panel
            title="P0 terkait PASILA"
            description="P0 POSKO yang mewarisi Section/Posko dari PASILA ini, termasuk usulan SYSTEM yang menunggu ACC dan kewajiban pengganti manual setelah penolakan."
            :requirements="['Domain P0 dengan relasi wajib ke PASILA', 'Workflow ACC/Tolak beserta audit']"
        />
        <x-admin.unavailable-panel
            title="Eskalasi & Pekerjaan Tertunda"
            description="Permintaan eskalasi dari temuan PASILA dan tindakan yang tertunda akan tampil setelah alur eskalasi tersedia."
            :requirements="['Tabel work_escalations', 'Keputusan Admin melalui Review & Validasi']"
        />
    </div>
</div>
@endsection
