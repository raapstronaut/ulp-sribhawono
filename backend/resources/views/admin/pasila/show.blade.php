@extends('layouts.admin')

@section('title', 'PASILA '.$posko->nama_posko)

@section('content')
@php
    use App\Models\PasilaSession;
    $workDateLabel = \Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y');
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $sectionTone = [PasilaSession::SECTION_MENUNGGU => 'amber', PasilaSession::SECTION_REKOMENDASI => 'blue', PasilaSession::SECTION_ADMIN => 'emerald'];
    $noSession = [
        'BERJALAN' => 'Sesi PASILA shift berjalan belum dibuka. Sesi dibuka oleh pembuka otomatis (pasila:open-shift) atau tombol "Buka sesi shift berjalan" di PASILA Monitor bila pembuka diaktifkan.',
        'BERAKHIR' => 'Tidak ada sesi PASILA tercatat untuk shift ini. Shift sudah berakhir; sesi tidak dibuat mundur.',
        'BELUM_MULAI' => 'Shift belum dimulai. Sesi PASILA dibuka pada awal shift.',
    ];
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        parent="PASILA"
        :parent-url="route('admin.pasila.index', $filters)"
        :title="$posko->nama_posko"
        state="partial"
        :description="'Shift '.$context['shift']['code'].' ('.$context['shift']['name'].', '.$context['shift']['time'].') hari operasional '.$workDateLabel.'. Status sesi PASILA, status WO APKUK, dan kewajiban P0 ditampilkan terpisah.'"
    >
        <x-slot:actions>
            <x-admin.unavailable-action label="Buat P0 dari PASILA" reason="P0 wajib dibuat dari sesi PASILA yang valid pada Section dan Posko yang sama; workflow P0 belum aktif." />
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.pasila.show', $posko)" :reset-url="route('admin.pasila.show', $posko)" :active="request()->hasAny(['work_date', 'shift_code'])">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal operasional" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
        </x-admin.filter-bar>
        <dl class="grid grid-cols-2 gap-5 p-4 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode Posko">{{ $posko->kode_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Status master">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="Shift">{{ 'Shift '.$context['shift']['code'].' · '.$context['shift']['name'] }}{{ $context['is_current'] ? ' (berjalan)' : '' }}</x-admin.detail-item>
            <x-admin.detail-item label="Jendela shift">{{ $fmt($context['window']['start']) }} – {{ $fmt($context['window']['end']) }}</x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <x-admin.panel title="Sesi PASILA shift ini" description="Satu sesi per Posko per shift. Status sesi terpisah dari status WO APKUK dan kewajiban P0.">
        @if ($load->failed('sessions'))
            <x-admin.error-state :inset="false" />
        @elseif ($session)
            <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4" data-session="{{ $session->code }}">
                <x-admin.detail-item label="Kode sesi">{{ $session->code }}</x-admin.detail-item>
                <x-admin.detail-item label="Status sesi"><x-admin.status-badge tone="blue">{{ $session->statusLabel() }}</x-admin.status-badge></x-admin.detail-item>
                <x-admin.detail-item label="Dibuka">
                    {{ $fmt($session->opened_at) }} · {{ $session->triggerLabel() }}{{ $session->openedBy ? ' · '.$session->openedBy->username : '' }}
                    @if ($session->openedLateMinutes() > 0)
                        <span class="block text-[11px] font-semibold text-amber-700">{{ $session->openedLateMinutes() }} menit setelah awal shift</span>
                    @endif
                </x-admin.detail-item>
                <x-admin.detail-item label="Penentuan Section"><x-admin.status-badge :tone="$sectionTone[$session->section_status] ?? 'slate'">{{ $session->sectionStatusLabel() }}</x-admin.status-badge></x-admin.detail-item>
                <x-admin.detail-item label="Penyulang">{{ $session->penyulang?->nama_penyulang ?? '-' }}</x-admin.detail-item>
                <x-admin.detail-item label="Section">{{ $session->section_name ?? '-' }}</x-admin.detail-item>
                <x-admin.detail-item label="Strategi rekomendasi">{{ ($session->recommendation['strategy'] ?? '-').' '.($session->recommendation['version'] ?? '') }} · {{ $session->recommendation['considered'] ?? 0 }} Section dipertimbangkan</x-admin.detail-item>
                <x-admin.detail-item label="Ditetapkan Admin">
                    @if ($session->section_set_at)
                        {{ $session->sectionSetBy?->username ?? '-' }} · {{ $fmt($session->section_set_at) }}
                        <span class="block text-[11px] text-slate-500">{{ $session->section_set_reason }}</span>
                    @else
                        -
                    @endif
                </x-admin.detail-item>
            </dl>
            <div class="mt-4 space-y-1 text-xs text-slate-600">
                @if (! $session->hasSection())
                    <p class="font-semibold text-amber-800" data-hold-reason>{{ $session->holdReasonLabel() }}. Yang dibutuhkan: Section Posko ini pada SECTION_PIC_RAW dengan Penyulang yang cocok master dan tepat satu POSKO PIC.</p>
                @elseif ($sectionCheck && ! $sectionCheck['valid'])
                    <p class="font-semibold text-red-700" data-section-warning>{{ $sectionCheck['note'] }}</p>
                @endif
                @if ($session->recommendation['recommended_section'] ?? null)
                    <p class="font-semibold text-slate-700" data-recommended-section>Rekomendasi sistem saat sesi dibuka: {{ $session->recommendation['recommended_section'] }} ({{ $session->recommendation['recommended_penyulang'] }})</p>
                @endif
                @foreach ($session->recommendation['reasons'] ?? [] as $reason)
                    <p>{{ $reason }}</p>
                @endforeach
            </div>
        @else
            <x-admin.empty-state title="Belum ada sesi PASILA." :description="$noSession[$context['phase']]" />
        @endif
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.panel title="Tentukan / ubah Section" description="Hanya Section terverifikasi milik Posko ini. Section milik Posko lain, tidak dikenal, atau ambigu ditolak. Alasan wajib; perubahan tercatat di Audit Log.">
            @if (! $session)
                <p class="text-xs text-slate-500">Tersedia setelah sesi PASILA shift ini dibuka.</p>
            @elseif ($session->shiftHasEnded())
                <p class="text-xs text-slate-500">Shift sudah berakhir; Section sesi tidak diubah agar riwayat tetap utuh.</p>
            @elseif ($load->failed('sections'))
                <x-admin.error-state :inset="false" />
            @elseif ($sections === [])
                <x-admin.empty-state title="Posko ini belum memiliki Section terverifikasi." description="Lengkapi SECTION_PIC_RAW sesuai kontrak referensi Section (PENYULANG, PEMUTUS, POSKO PIC). Sesi tetap tercatat dengan status menunggu penentuan Section." />
            @else
                <form method="POST" action="{{ route('admin.pasila.section', $session) }}" class="space-y-3">
                    @csrf
                    <x-admin.select name="section" label="Section" :options="$sectionOptions" :value="$session->section_name" placeholder="Pilih Section terverifikasi" required />
                    <x-admin.textarea name="reason" label="Alasan" required hint="Minimal 10 karakter, mis. kondisi lapangan atau arahan atasan." />
                    <x-admin.button>Simpan Section</x-admin.button>
                </form>
            @endif
        </x-admin.panel>

        <x-admin.panel flush title="Section terverifikasi Posko ini" description="Dari SECTION_PIC_RAW; dapat mencakup beberapa Penyulang. Riwayat penugasan dipakai rotasi rekomendasi.">
            @if ($load->failed('sections', 'history'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[480px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Penyulang</th>
                        <th class="px-4 py-3">Section</th>
                        <th class="px-4 py-3">Terakhir ditugaskan PASILA</th>
                    </x-slot:head>
                    @forelse ($sections as $entry)
                        @php $last = $lastAssigned->get($entry['key']); @endphp
                        <tr data-owned-section="{{ $entry['section'] }}">
                            <td class="px-4 py-3 text-xs">{{ $entry['penyulang'] }}</td>
                            <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $entry['section'] }}</td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $last ? 'Shift '.$last->shift_code.' · '.\Illuminate\Support\Carbon::parse($last->operational_date)->format('d/m/Y') : 'Belum pernah' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-admin.empty-state title="Belum ada Section terverifikasi." description="Section ditahan bila Penyulang tidak cocok master, POSKO PIC kosong/tidak dikenal/lebih dari satu, atau Section tercatat di beberapa Penyulang." /></td></tr>
                    @endforelse
                </x-admin.table>
            @endif
        </x-admin.panel>
    </div>

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

    <x-admin.panel flush title="WO APKUK pada shift ini" description="Status WO APKUK terpisah dari sesi PASILA. WO yang belum selesai atau sedang diajukan reschedule tidak menahan sesi. Enam WO adalah target pekerjaan per shift ({{ $targetPerShift }}); jumlah APKUK fisik dicatat terpisah.">
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

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-admin.unavailable-panel
            title="Temuan per Titik"
            description="Daftar temuan sesi ini (jenis/kode, aset/koordinat, foto, GPS, petugas, waktu per temuan) akan tampil di sini."
            :requirements="['Tabel temuan PASILA dan media per temuan (tahap berikutnya)', 'Kode temuan resmi']"
        />
        <x-admin.unavailable-panel
            title="P0 terkait PASILA"
            description="P0 POSKO yang mewarisi Section/Posko dari sesi ini, termasuk usulan SYSTEM yang menunggu ACC dan kewajiban pengganti manual setelah penolakan."
            :requirements="['Workflow P0 terikat sesi PASILA (tahap berikutnya)', 'Skema detail P0 (menunggu konfirmasi atasan)']"
        />
        <x-admin.unavailable-panel
            title="Eskalasi & Pekerjaan Tertunda"
            description="Permintaan eskalasi dari temuan PASILA dan tindakan yang tertunda akan tampil setelah alur eskalasi tersedia."
            :requirements="['Tabel work_escalations', 'Keputusan Admin melalui Review & Validasi']"
        />
    </div>
</div>
@endsection
