@extends('layouts.admin')

@section('title', 'PASILA')

@section('content')
@php
    use App\Models\PasilaSession;
    use App\Services\Apkuk\SectionPoskoMap;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $sectionTone = [PasilaSession::SECTION_MENUNGGU => 'amber', PasilaSession::SECTION_REKOMENDASI => 'blue', PasilaSession::SECTION_ADMIN => 'emerald'];
    $patrolTone = [PasilaSession::PATROL_BELUM => 'slate', PasilaSession::PATROL_ADA_TEMUAN => 'violet', PasilaSession::PATROL_NIHIL => 'emerald'];
    $noSession = [
        'BERJALAN' => ['Belum dibuka', 'Pembuka sesi belum dijalankan untuk shift berjalan.'],
        'BERAKHIR' => ['Tidak ada sesi', 'Shift sudah berakhir; sesi tidak dibuat mundur.'],
        'BELUM_MULAI' => ['Shift belum dimulai', 'Sesi dibuka pada awal shift.'],
    ];
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.page-header
        section="Pekerjaan"
        title="PASILA Monitor"
        state="partial"
        description="Sesi PASILA dibuka otomatis pada awal Shift A, B, dan C untuk setiap Posko, tanpa menunggu WO APKUK selesai. Section yang dipatroli dipilih dari Section terverifikasi milik Posko; kepemilikan Section tidak berpindah antar-Posko."
    >
        <x-slot:actions>
            @if ($blockedReason)
                <x-admin.unavailable-action label="Buka sesi shift berjalan" :reason="$blockedReason" />
            @elseif (! $context['is_current'])
                <x-admin.unavailable-action label="Buka sesi shift berjalan" reason="Hanya shift yang sedang berjalan yang dapat dibuka; sesi tidak dibuat mundur atau lebih awal." />
            @else
                <form method="POST" action="{{ route('admin.pasila.open') }}" data-confirm="Buka sesi PASILA shift berjalan untuk Posko aktif yang belum memiliki sesi?">
                    @csrf
                    <x-admin.button>Buka sesi shift berjalan</x-admin.button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice tone="warning" title="Belum operasional.">
        Pembuka sesi otomatis {{ $blockedReason ? 'nonaktif di lingkungan ini' : 'aktif di lingkungan ini' }}.
        Rekomendasi Section memakai strategi {{ $strategy->name() }} {{ $strategy->version() }}: <strong>rotasi baseline, bukan skor risiko, bukan ML, dan tidak memakai data gangguan</strong>.
        Referensi Section–Posko berasal dari SECTION_PIC_RAW (staging, belum disahkan sebagai data resmi); Section yang tidak lengkap atau ambigu ditahan, bukan ditebak.
        Temuan per titik dan laporan nihil dicatat petugas POSKO lewat aplikasi; daftar temuan kosong tanpa laporan nihil berarti <strong>belum ada laporan patroli</strong>, bukan nihil. Target P0 (minimal {{ \App\Services\Pasila\P0Service::TARGET_PER_SHIFT }} per Posko per shift) terpenuhi hanya oleh P0 yang selesai; P0 pengganti yang baru dibuat memenuhi kewajiban penggantian Admin, bukan target. PASILA boleh nihil walau target P0 belum terpenuhi.
    </x-admin.notice>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
        <x-admin.stat-card label="Sesi ditutup" :value="$load->failed('sessions') ? null : $totals['closed']" :hint="'Dari '.$totals['sessions'].' sesi; perlu laporan patroli'" unavailable-label="Gagal dimuat" tone="emerald" />
        <x-admin.stat-card label="Posko aktif" :value="$load->failed('poskos') ? null : $totals['poskos']" hint="Dari master poskos" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Sesi PASILA" :value="$load->failed('sessions') ? null : $totals['sessions']" :hint="'Tercatat untuk shift ini dari '.$totals['poskos'].' Posko'" unavailable-label="Gagal dimuat" tone="blue" />
        <x-admin.stat-card label="Menunggu Section" :value="$load->failed('sessions') ? null : $totals['waiting']" hint="Sesi tanpa Section terverifikasi" unavailable-label="Gagal dimuat" tone="amber" />
        <x-admin.stat-card label="WO APKUK shift" :value="$load->failed('work_orders') ? null : $totals['work_orders']" :hint="'Status WO terpisah dari sesi; target '.$targetPerShift.' per Posko'" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Target P0 terpenuhi" :value="$load->failed('sessions') ? null : $rows->filter(fn ($r) => $r['p0']['fulfilled'] ?? false)->count()" :hint="'Sesi dengan P0 selesai, dari '.$totals['sessions'].' sesi'" :href="route('admin.p0.index')" unavailable-label="Gagal dimuat" />
    </div>

    <x-admin.panel flush :title="'Shift '.$context['shift']['code'].' · '.$context['shift']['name'].' — '.\Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y')" :description="$context['shift']['time'].' · hari operasional '.\Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y').' · '.$fmt($context['window']['start']).' – '.$fmt($context['window']['end']).($context['is_current'] ? ' · shift berjalan' : ' · bukan shift berjalan')">
        <x-admin.filter-bar :action="route('admin.pasila.index')" :reset-url="route('admin.pasila.index')" :active="$hasFilter">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal operasional" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko aktif" />
        </x-admin.filter-bar>

        @if ($load->failed('poskos', 'sessions', 'work_orders', 'reschedules'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[1240px]">
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th>
                    <th class="px-4 py-3">Sesi PASILA</th>
                    <th class="px-4 py-3">Penyulang · Section</th>
                    <th class="px-4 py-3">Laporan patroli</th>
                    <th class="px-4 py-3">WO APKUK (selesai / target)</th>
                    <th class="px-4 py-3">Reschedule diajukan</th>
                    <th class="px-4 py-3">Target P0 · pengganti</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($rows as $row)
                    @php $session = $row['session']; @endphp
                    <tr class="hover:bg-slate-50/60" data-posko="{{ $row['posko']->nama_posko }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-900">{{ $row['posko']->nama_posko }}</div>
                            <div class="text-[10px] text-slate-500">{{ $row['posko']->kode_posko }}</div>
                        </td>
                        <td class="px-4 py-3" data-session-status="{{ $session?->status ?? 'TIDAK_ADA' }}">
                            @if ($session)
                                <x-admin.status-badge :tone="$session->isClosed() ? 'emerald' : 'blue'">{{ $session->statusLabel() }}</x-admin.status-badge>
                                <div class="mt-1 text-[10px] font-semibold text-slate-700">{{ $session->code }}</div>
                                <div class="text-[10px] text-slate-500">{{ $fmt($session->opened_at) }} · {{ $session->triggerLabel() }}</div>
                                @if ($session->openedLateMinutes() > 0)
                                    <div class="text-[10px] font-semibold text-amber-700">Dibuka {{ $session->openedLateMinutes() }} menit setelah awal shift</div>
                                @endif
                                @if ($session->closure)
                                    <div class="text-[10px] text-slate-600" data-closure="{{ $session->closure->outcome }}">{{ $session->closure->outcomeLabel() }} · {{ $fmt($session->closure->occurred_at) }}</div>
                                    @foreach ($session->closureFlags() as $flag => $text)
                                        <div class="text-[10px] font-semibold text-amber-700" data-closure-flag="{{ $flag }}">{{ $text }}</div>
                                    @endforeach
                                @endif
                            @else
                                <x-admin.status-badge tone="slate">{{ $noSession[$context['phase']][0] }}</x-admin.status-badge>
                                <div class="mt-1 text-[10px] text-slate-500">{{ $noSession[$context['phase']][1] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-section-status="{{ $session?->section_status ?? '' }}">
                            @if ($session)
                                <x-admin.status-badge :tone="$sectionTone[$session->section_status] ?? 'slate'">{{ $session->sectionStatusLabel() }}</x-admin.status-badge>
                                @if ($session->hasSection())
                                    <div class="mt-1 text-xs font-semibold text-slate-800">{{ $session->section_name }}</div>
                                    <div class="text-[10px] text-slate-500">{{ $session->penyulang?->nama_penyulang ?? '-' }}</div>
                                    @if ($row['section_check'] && ! $row['section_check']['valid'])
                                        <div class="text-[10px] font-semibold text-red-700">{{ $row['section_check']['note'] }}</div>
                                    @endif
                                @else
                                    <div class="mt-1 text-[10px] text-amber-800">{{ $session->holdReasonLabel() }}</div>
                                @endif
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-patrol-status="{{ $session?->patrolStatus() ?? '' }}">
                            @if ($session)
                                <x-admin.status-badge :tone="$patrolTone[$session->patrolStatus()]">{{ $session->patrolStatusLabel() }}</x-admin.status-badge>
                                <div class="mt-1 text-[10px] text-slate-500">{{ $session->findings_count }} temuan</div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="w-14 shrink-0 text-xs font-bold text-slate-800">{{ $row['selesai'] }} / {{ $targetPerShift }}</span>
                                <x-admin.bar :value="$row['selesai']" :max="$targetPerShift" :label="$row['selesai'].' selesai dari target '.$targetPerShift" />
                            </div>
                            <div class="mt-1 text-[10px] text-slate-500">{{ $row['total'] }} WO tercatat · {{ $row['aktif'] }} aktif</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($row['reschedule'] > 0)
                                <x-admin.status-badge tone="amber">{{ $row['reschedule'] }} menunggu</x-admin.status-badge>
                            @else
                                <span class="text-xs text-slate-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-[11px]" data-p0-obligation="{{ $row['p0'] === null ? '' : ($row['p0']['fulfilled'] ? 'TERPENUHI' : 'BELUM') }}">
                            @if ($row['p0'] === null)
                                <span class="text-slate-400">—</span>
                            @else
                                <x-admin.status-badge :tone="$row['p0']['fulfilled'] ? 'emerald' : 'amber'">Target {{ $row['p0']['fulfilled'] ? 'terpenuhi' : 'belum terpenuhi' }}</x-admin.status-badge>
                                <div class="mt-1 text-[10px] text-slate-500">
                                    {{ $row['p0']['completed'] }}/{{ $row['p0']['target'] }} P0 selesai
                                    @foreach ([\App\Models\P0Item::STAGE_AKTIF => 'aktif', \App\Models\P0Item::STAGE_MENUNGGU_VERIFIKASI => 'menunggu verifikasi', \App\Models\P0Item::STAGE_MENUNGGU_ACC => 'menunggu ACC'] as $stage => $text)
                                        @if (($row['p0']['stages'][$stage] ?? 0) > 0) · {{ $row['p0']['stages'][$stage] }} {{ $text }} @endif
                                    @endforeach
                                </div>
                                @if ($row['p0']['replacement_open'] > 0)
                                    <div class="mt-1 text-[10px] font-semibold text-red-700" data-p0-replacement-open="{{ $row['p0']['replacement_open'] }}">Pengganti wajib dibuat Admin: {{ $row['p0']['replacement_open'] }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('admin.pasila.show', ['posko' => $row['posko'], 'work_date' => $context['work_date'], 'shift_code' => $context['shift_code']]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-admin.empty-state title="Belum ada Posko aktif." description="Master Posko aktif kosong atau tidak cocok dengan filter." />
                        </td>
                    </tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="Referensi Section per Posko" description="Sumber: SECTION_PIC_RAW (PENYULANG, PEMUTUS, POSKO PIC). Satu Posko dapat memegang banyak Section di banyak Penyulang; Penyulang tidak menentukan Posko. Kepemilikan tetap; yang berganti tiap shift hanya rekomendasi Section yang dipatroli.">
        @if ($load->failed('sections', 'poskos'))
            <x-admin.error-state />
        @else
            <x-admin.table min-width="min-w-[720px]">
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th>
                    <th class="px-4 py-3 text-right">Section terverifikasi</th>
                    <th class="px-4 py-3">Penyulang</th>
                </x-slot:head>
                @foreach ($poskos as $posko)
                    @php $owned = $verifiedByPosko->get($posko->id, collect()); @endphp
                    <tr data-reference-posko="{{ $posko->nama_posko }}">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $posko->nama_posko }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold {{ $owned->isEmpty() ? 'text-amber-700' : 'text-slate-800' }}">{{ $owned->count() }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            @if ($owned->isEmpty())
                                <span class="text-amber-800">Belum ada Section terverifikasi; sesi PASILA Posko ini menunggu penentuan Section.</span>
                            @else
                                {{ $owned->pluck('penyulang')->unique()->implode(', ') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            @if ($heldEntries->isNotEmpty())
                <div class="border-t border-slate-100 px-4 pb-2 pt-4 text-xs font-semibold text-slate-700">Referensi ditahan ({{ $heldEntries->count() }}) — tidak dipakai untuk penugasan sampai datanya lengkap</div>
                <x-admin.table min-width="min-w-[720px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Penyulang</th>
                        <th class="px-4 py-3">Section</th>
                        <th class="px-4 py-3">POSKO PIC sumber</th>
                        <th class="px-4 py-3">Alasan ditahan</th>
                    </x-slot:head>
                    @foreach ($heldEntries as $entry)
                        <tr data-held-section="{{ $entry['section'] }}">
                            <td class="px-4 py-3 text-xs">{{ $entry['penyulang'] }}</td>
                            <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $entry['section'] }}</td>
                            <td class="px-4 py-3 text-xs">{{ implode(', ', $entry['posko_pic']) ?: '-' }}</td>
                            <td class="px-4 py-3 text-xs text-amber-800">{{ SectionPoskoMap::LABELS[$entry['reason']] ?? $entry['reason'] }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        @endif
    </x-admin.panel>
</div>
@endsection
