@extends('layouts.admin')

@section('title', 'Review & Validasi')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $decisionReason = 'Keputusan ACC/Tolak belum diaktifkan: validasi, transisi status, dan audit backend untuk keputusan Admin belum lengkap.';
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Kontrol"
        title="Review & Validasi"
        state="partial"
        description="Antrean keputusan Admin: usulan P0 SYSTEM, temuan INSPEKSI, eskalasi lintas tim, laporan yang perlu verifikasi, dan pengajuan reschedule."
    />

    <x-admin.notice tone="warning" title="Baca saja:">
        Tombol ACC/Tolak sengaja dinonaktifkan sampai workflow keputusan beserta auditnya tersedia di backend. Kebijakan approval reschedule per jenis pekerjaan masih TBD, sehingga reschedule tidak otomatis dianggap wajib ACC.
    </x-admin.notice>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-admin.stat-card label="Laporan menunggu verifikasi" :value="$counts['reports']" hint="work_order_reports berstatus MENUNGGU_VERIFIKASI" :tone="($counts['reports'] ?? 0) > 0 ? 'amber' : 'slate'" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Reschedule diajukan" :value="$counts['reschedules']" hint="work_order_reschedules berstatus MENUNGGU_ACC" :tone="($counts['reschedules'] ?? 0) > 0 ? 'amber' : 'slate'" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="P0 SYSTEM menunggu ACC" hint="Domain P0 belum tersedia" />
        <x-admin.stat-card label="Temuan INSPEKSI" hint="Domain temuan belum tersedia" />
        <x-admin.stat-card label="Eskalasi lintas tim" hint="Domain eskalasi belum tersedia" />
    </div>

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.reviews.index')" :reset-url="route('admin.reviews.index')" :active="$hasFilter">
            <x-admin.filter-select name="category" label="Kategori" :options="\App\Http\Controllers\Admin\ReviewController::CATEGORIES" :value="$filters['category']" placeholder="Semua antrean" />
            <x-admin.filter-select name="team_type" label="Tim" :options="\App\Http\Controllers\Admin\ReviewController::TEAM_TYPES" :value="$filters['team_type']" placeholder="Semua tim" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko" />
        </x-admin.filter-bar>
    </x-admin.panel>

    @if ($showReports)
        <x-admin.panel flush title="Laporan Menunggu Verifikasi" description="Contoh: laporan APKUK di tiang ternyata sudah lengkap, yang membutuhkan verifikasi Admin sebelum WO selesai.">
            @if ($load->failed('reports'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[900px]">
                    <x-slot:head>
                        <th class="px-4 py-3">WO</th>
                        <th class="px-4 py-3">Jenis laporan</th>
                        <th class="px-4 py-3">Posko / Tim</th>
                        <th class="px-4 py-3">Waktu kejadian</th>
                        <th class="px-4 py-3">Diterima</th>
                        <th class="px-4 py-3 text-center">Keputusan</th>
                    </x-slot:head>
                    @forelse ($reports as $report)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.work-orders.show', $report->workOrder) }}#laporan" class="font-semibold text-blue-600 hover:text-blue-800">{{ $report->workOrder->code }}</a>
                                <div class="text-[10px] text-slate-500">{{ $report->workOrder->team_type }} · {{ $report->workOrder->work_type }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $report->report_type }}</td>
                            <td class="px-4 py-3">
                                <div>{{ $report->workOrder->posko?->nama_posko ?? '-' }}</div>
                                <div class="text-[10px] text-slate-500">{{ $report->submittedByTeam?->name ?? 'Tim tidak tercatat' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $report->occurred_at ? $report->occurred_at->timezone($tz)->format('d/m/Y H:i').' WIB' : '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ ($report->received_at ?? $report->created_at)?->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-1">
                                    <x-admin.unavailable-action label="ACC" variant="secondary" size="sm" :reason="$decisionReason" />
                                    <x-admin.unavailable-action label="Tolak" variant="secondary" size="sm" :reason="$decisionReason" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-admin.empty-state title="Tidak ada laporan yang menunggu verifikasi." description="Belum ada laporan lapangan berstatus MENUNGGU_VERIFIKASI atau tidak cocok dengan filter." /></td></tr>
                    @endforelse
                </x-admin.table>
                <x-admin.pagination :paginator="$reports" label="laporan" />
            @endif
        </x-admin.panel>
    @endif

    @if ($showReschedules)
        <x-admin.panel flush title="Pengajuan Reschedule" description="Diajukan petugas dengan alasan dropdown + catatan manual wajib dan jadwal tujuan pilihan petugas.">
            @if ($load->failed('reschedules'))
                <x-admin.error-state />
            @else
                <x-admin.table min-width="min-w-[960px]">
                    <x-slot:head>
                        <th class="px-4 py-3">WO</th>
                        <th class="px-4 py-3">Alasan & catatan</th>
                        <th class="px-4 py-3">Jadwal asal → tujuan</th>
                        <th class="px-4 py-3">Diajukan</th>
                        <th class="px-4 py-3 text-center">Keputusan</th>
                    </x-slot:head>
                    @forelse ($reschedules as $reschedule)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.work-orders.show', $reschedule->workOrder) }}#reschedule" class="font-semibold text-blue-600 hover:text-blue-800">{{ $reschedule->workOrder->code }}</a>
                                <div class="text-[10px] text-slate-500">{{ $reschedule->workOrder->posko?->nama_posko ?? $reschedule->workOrder->team_type }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs font-semibold text-slate-800">{{ $reschedule->reason_code }}</div>
                                <div class="max-w-xs text-[11px] text-slate-500">{{ $reschedule->note }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $reschedule->from_work_date?->format('d/m/Y') }} {{ $reschedule->from_shift_code }} → {{ $reschedule->proposed_work_date?->format('d/m/Y') }} {{ $reschedule->proposed_shift_code }}</td>
                            <td class="px-4 py-3">
                                <div class="text-xs">{{ $reschedule->created_at?->timezone($tz)->format('d/m/Y H:i') }} WIB</div>
                                <div class="text-[10px] text-slate-500">{{ $reschedule->requestedByTeam?->name ?? 'Tim tidak tercatat' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-1">
                                    <x-admin.unavailable-action label="ACC" variant="secondary" size="sm" :reason="$decisionReason" />
                                    <x-admin.unavailable-action label="Tolak" variant="secondary" size="sm" :reason="$decisionReason" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-admin.empty-state title="Tidak ada pengajuan reschedule." description="Belum ada reschedule berstatus MENUNGGU_ACC atau tidak cocok dengan filter." /></td></tr>
                    @endforelse
                </x-admin.table>
                <x-admin.pagination :paginator="$reschedules" label="pengajuan reschedule" />
            @endif
        </x-admin.panel>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-admin.unavailable-panel
            title="Usulan P0 SYSTEM"
            description="Usulan P0 dari sistem di dalam PASILA wajib di-ACC Admin sebelum aktif. Bila ditolak, Admin wajib membuat P0 manual pengganti dalam PASILA yang sama."
            :requirements="['Domain PASILA dan P0', 'Kewajiban pengganti tercatat setelah penolakan']"
        />
        <x-admin.unavailable-panel
            title="Temuan INSPEKSI"
            description="Temuan beserta rekomendasi tim penerima menunggu ACC/Tolak Admin sebelum memutakhirkan master aset."
            :requirements="['Tabel inspection_findings', 'Klasifikasi dan rekomendasi tim (TBD)']"
        />
        <x-admin.unavailable-panel
            title="Eskalasi Lintas Tim"
            description="ACC membuat child WO di tim tujuan dan menandai sumber SELESAI_DITERUSKAN secara atomik; penolakan tidak membuat child WO."
            :requirements="['Tabel work_escalations dan approvals', 'Transaksi ACC atomik beserta audit']"
        />
    </div>
</div>
@endsection
