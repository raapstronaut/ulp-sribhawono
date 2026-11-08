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
<div class="mx-auto max-w-[1500px] space-y-5">
    <x-admin.posko-tabs />
    <x-admin.page-header
        section="Pekerjaan"
        title="PASILA Monitor"
        state="partial"
        description="Pantau sesi patroli enam Posko, penentuan Section, laporan patroli, dan kewajiban P0 untuk setiap shift."
    >
        <x-slot:actions>
            @if ($blockedReason)
                <x-admin.unavailable-action label="Buka sesi shift berjalan" :reason="$blockedReason" />
            @elseif (! $context['is_current'])
                <x-admin.unavailable-action label="Buka sesi shift berjalan" reason="Hanya shift berjalan yang dapat dibuka." />
            @else
                <form method="POST" action="{{ route('admin.pasila.open') }}" data-confirm="Buka sesi PASILA shift berjalan untuk Posko aktif yang belum memiliki sesi?">
                    @csrf
                    <x-admin.button>Buka sesi shift berjalan</x-admin.button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div data-pasila-redesign-v2 class="rounded-xl border border-blue-100 bg-gradient-to-r from-blue-50 via-white to-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wider text-white">Monitor shift</span>
                    <span class="rounded-md border px-2.5 py-1 text-[11px] font-semibold {{ $context['is_current'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600' }}">
                        {{ $context['is_current'] ? 'Shift berjalan' : 'Riwayat / shift lain' }}
                    </span>
                    @if ($blockedReason)
                        <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800">Pembukaan sesi nonaktif</span>
                    @endif
                </div>
                <h2 class="mt-3 text-lg font-extrabold tracking-tight text-slate-900 sm:text-xl">
                    Shift {{ $context['shift']['code'] }} · {{ $context['shift']['name'] }}
                    <span class="font-medium text-slate-500">/ {{ \Illuminate\Support\Carbon::parse($context['work_date'])->format('d M Y') }}</span>
                </h2>
                <p class="mt-1 text-xs leading-relaxed text-slate-600">
                    {{ $fmt($context['window']['start']) }} – {{ $fmt($context['window']['end']) }}.
                    Data sesi, laporan patroli, WO APKUK, dan P0 adalah indikator yang berbeda.
                </p>
            </div>
            <div class="shrink-0 rounded-lg border border-blue-100 bg-white px-4 py-3 text-xs text-slate-600 sm:max-w-[280px]">
                <p class="font-bold text-slate-800">Status pelaporan</p>
                <p class="mt-1">Sesi dibuka <strong>tidak</strong> berarti patroli selesai. Laporan nihil harus dicatat petugas, bukan diasumsikan.</p>
            </div>
        </div>
    </div>

    <details class="group rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-xs text-amber-950">
        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 font-semibold marker:hidden">
            <span>Catatan kesiapan dan batasan data PASILA</span>
            <span class="text-[11px] font-medium text-amber-800 group-open:hidden">Lihat detail +</span>
            <span class="hidden text-[11px] font-medium text-amber-800 group-open:inline">Tutup âˆ’</span>
        </summary>
        <div class="mt-3 space-y-2 border-t border-amber-200 pt-3 leading-relaxed text-amber-900">
            <p>Pembukaan sesi {{ $blockedReason ? 'nonaktif di lingkungan ini' : 'diizinkan oleh konfigurasi' }}. Pembukaan otomatis tetap memerlukan scheduler yang berjalan; status scheduler tidak disimpulkan dari konfigurasi ini.</p>
            <p>Strategi {{ $strategy->name() }} {{ $strategy->version() }} adalah rotasi baseline, bukan ML atau skor risiko. Referensi Section–Posko berasal dari SECTION_PIC_RAW yang masih staging; entri ambigu ditahan.</p>
            <p>Tanpa temuan <strong>dan</strong> tanpa laporan nihil berarti <strong>belum ada laporan patroli</strong>. Target P0 minimal {{ \App\Services\Pasila\P0Service::TARGET_PER_SHIFT }} yang selesai per Posko per shift. P0 pengganti baru belum memenuhi target; PASILA boleh nihil walau target P0 belum terpenuhi.</p>
        </div>
    </details>

    @php
        $unreported = $rows->filter(fn ($r) => $r['session'] && $r['session']->patrolStatus() === PasilaSession::PATROL_BELUM)->count();
        $reported = $rows->filter(fn ($r) => $r['session'] && $r['session']->patrolStatus() !== PasilaSession::PATROL_BELUM)->count();
    @endphp
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="Ringkasan PASILA shift">
        <x-admin.stat-card label="Posko aktif" :value="$load->failed('poskos') ? null : $totals['poskos']" hint="Posko terdaftar" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Sesi PASILA" :value="$load->failed('sessions') ? null : $totals['sessions']" hint="Sesi pada shift terpilih" unavailable-label="Gagal dimuat" tone="blue" />
        <x-admin.stat-card label="Belum lapor patroli" :value="$load->failed('sessions') ? null : $unreported" hint="Sesi tanpa laporan patroli" unavailable-label="Gagal dimuat" tone="amber" />
        <x-admin.stat-card label="Sudah lapor patroli" :value="$load->failed('sessions') ? null : $reported" hint="Ada temuan atau laporan nihil" unavailable-label="Gagal dimuat" tone="emerald" />
        <x-admin.stat-card label="Sesi ditutup" :value="$load->failed('sessions') ? null : $totals['closed']" hint="Sesi yang telah ditutup" unavailable-label="Gagal dimuat" />
        <x-admin.stat-card label="Menunggu Section" :value="$load->failed('sessions') ? null : $totals['waiting']" hint="Section belum ditentukan" unavailable-label="Gagal dimuat" tone="amber" />
    </div>



    <x-admin.panel flush title="Monitoring POSKO" :description="'Shift '.$context['shift']['code'].' · '.$context['shift']['name'].' · '.\Illuminate\Support\Carbon::parse($context['work_date'])->format('d/m/Y').' · '.($context['is_current'] ? 'berjalan' : 'dipilih')">
        <x-admin.filter-bar :action="route('admin.pasila.index')" :reset-url="route('admin.pasila.index')" :active="$hasFilter">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal operasional" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
            <x-admin.filter-select name="posko_id" label="Posko" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua Posko aktif" />
        </x-admin.filter-bar>
        @if ($load->failed('poskos', 'sessions', 'work_orders', 'reschedules'))
            <x-admin.error-state />
        @else
            <div class="flex flex-col gap-2 border-y border-slate-100 bg-slate-50/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs font-semibold text-slate-700">{{ $rows->count() }} POSKO ditampilkan <span class="font-normal text-slate-500">· Klik Detail untuk melihat laporan dan P0 per Posko.</span></p>
                <p class="text-[11px] text-slate-500">WO APKUK shift: {{ $totals['work_orders'] }} tercatat · Target {{ $targetPerShift }} selesai / Posko</p>
            </div>
            <x-admin.table min-width="min-w-[1060px]">
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th>
                    <th class="px-4 py-3">Sesi PASILA</th>
                    <th class="px-4 py-3">Penyulang · Section</th>
                    <th class="px-4 py-3">Laporan patroli</th>
                    <th class="px-4 py-3">WO APKUK / reschedule</th>
                    <th class="px-4 py-3">Target P0 · pengganti</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @forelse ($rows as $row)
                    @php $session = $row['session']; @endphp
                    <tr class="align-top transition-colors hover:bg-blue-50/40" data-posko="{{ $row['posko']->nama_posko }}">
                        <td class="px-4 py-4">
                            <div class="text-sm font-extrabold text-slate-900">{{ $row['posko']->nama_posko }}</div>
                            <div class="mt-1 text-[11px] text-slate-500">{{ $row['posko']->kode_posko }}</div>
                        </td>
                        <td class="px-4 py-4" data-session-status="{{ $session?->status ?? 'TIDAK_ADA' }}">
                            @if ($session)
                                @php $unclosedLabel = $session->unclosedAfterShiftLabel(); @endphp
                                <x-admin.status-badge :tone="$session->isClosed() ? 'emerald' : ($unclosedLabel ? 'amber' : 'blue')">{{ $session->statusLabel() }}</x-admin.status-badge>
                                <div class="mt-1.5 max-w-[190px] break-all text-[11px] font-semibold text-slate-700">{{ $session->code }}</div>
                                <div class="mt-1 text-[11px] text-slate-500">{{ $fmt($session->opened_at) }}</div>
                                @if ($unclosedLabel)
                                    <div class="mt-1 text-[11px] font-semibold text-amber-700" data-unclosed-label>{{ $unclosedLabel }}</div>
                                @endif
                                @if ($session->openedLateMinutes() > 0)
                                    <div class="mt-1 text-[11px] text-amber-700">Dibuka {{ $session->openedLateMinutes() }} menit setelah awal shift</div>
                                @endif
                                @if ($session->closure)
                                    <div class="mt-1 text-[11px] text-slate-600" data-closure="{{ $session->closure->outcome }}">{{ $session->closure->outcomeLabel() }} · {{ $fmt($session->closure->occurred_at) }}</div>
                                    @foreach ($session->closureFlags() as $flag => $text)
                                        <div class="text-[11px] font-semibold text-amber-700" data-closure-flag="{{ $flag }}">{{ $text }}</div>
                                    @endforeach
                                @endif
                            @else
                                <x-admin.status-badge tone="slate">{{ $noSession[$context['phase']][0] }}</x-admin.status-badge>
                                <div class="mt-1 text-[11px] text-slate-500">{{ $noSession[$context['phase']][1] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-4" data-section-status="{{ $session?->section_status ?? '' }}">
                            @if ($session)
                                <x-admin.status-badge :tone="$sectionTone[$session->section_status] ?? 'slate'">{{ $session->sectionStatusLabel() }}</x-admin.status-badge>
                                @if ($session->hasSection())
                                    <div class="mt-1.5 text-xs font-bold text-slate-800">{{ $session->section_name }}</div>
                                    <div class="mt-1 text-[11px] text-slate-500">{{ $session->penyulang?->nama_penyulang ?? '-' }}</div>
                                    @if ($row['section_check'] && ! $row['section_check']['valid'])
                                        <div class="mt-1 text-[11px] font-semibold text-red-700">{{ $row['section_check']['note'] }}</div>
                                    @endif
                                @else
                                    <div class="mt-1 text-[11px] text-amber-800">{{ $session->holdReasonLabel() }}</div>
                                @endif
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4" data-patrol-status="{{ $session?->patrolStatus() ?? '' }}">
                            @if ($session)
                                <x-admin.status-badge :tone="$patrolTone[$session->patrolStatus()]">{{ $session->patrolStatusLabel() }}</x-admin.status-badge>
                                <div class="mt-2 text-[11px] text-slate-500">{{ $session->findings_count }} temuan tercatat</div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-2">
                                <span class="w-12 shrink-0 text-xs font-extrabold text-slate-800">{{ $row['selesai'] }}/{{ $targetPerShift }}</span>
                                <x-admin.bar :value="$row['selesai']" :max="$targetPerShift" :label="$row['selesai'].' selesai dari target '.$targetPerShift" />
                            </div>
                            <div class="mt-2 text-[11px] text-slate-500">{{ $row['total'] }} WO · {{ $row['aktif'] }} aktif</div>
                            @if ($row['reschedule'] > 0)
                                <div class="mt-1"><x-admin.status-badge tone="amber">{{ $row['reschedule'] }} reschedule menunggu</x-admin.status-badge></div>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-[11px]" data-p0-obligation="{{ $row['p0'] === null ? '' : ($row['p0']['fulfilled'] ? 'TERPENUHI' : 'BELUM') }}">
                            @if ($row['p0'] === null)
                                <span class="text-slate-400">—</span>
                            @else
                                <x-admin.status-badge :tone="$row['p0']['fulfilled'] ? 'emerald' : 'amber'">Target {{ $row['p0']['fulfilled'] ? 'terpenuhi' : 'belum terpenuhi' }}</x-admin.status-badge>
                                <div class="mt-1.5 font-semibold text-slate-700">{{ $row['p0']['completed'] }}/{{ $row['p0']['target'] }} P0 selesai</div>
                                @foreach ([\App\Models\P0Item::STAGE_AKTIF => 'aktif', \App\Models\P0Item::STAGE_MENUNGGU_VERIFIKASI => 'menunggu verifikasi', \App\Models\P0Item::STAGE_MENUNGGU_ACC => 'menunggu ACC'] as $stage => $text)
                                    @if (($row['p0']['stages'][$stage] ?? 0) > 0)
                                        <div class="mt-1 text-slate-500">{{ $row['p0']['stages'][$stage] }} {{ $text }}</div>
                                    @endif
                                @endforeach
                                @if ($row['p0']['replacement_open'] > 0)
                                    <div class="mt-1 font-semibold text-red-700" data-p0-replacement-open="{{ $row['p0']['replacement_open'] }}">Pengganti wajib dibuat Admin: {{ $row['p0']['replacement_open'] }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-4 text-center">
                            <a href="{{ route('admin.pasila.show', ['posko' => $row['posko'], 'work_date' => $context['work_date'], 'shift_code' => $context['shift_code']]) }}" class="inline-flex rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-admin.empty-state title="Belum ada Posko aktif." description="Master Posko aktif kosong atau tidak cocok dengan filter." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>



    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <details class="group min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" @if ($load->failed('unclosed') || $unclosed['total'] > 0) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-4 font-semibold text-slate-800 marker:hidden">
                <span>Sesi lewat shift belum ditutup <span class="ml-1 rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-700">{{ $load->failed('unclosed') ? '!' : $unclosed['total'] }}</span></span>
                <span class="text-xs text-blue-600 group-open:hidden">Buka +</span><span class="hidden text-xs text-blue-600 group-open:inline">Tutup âˆ’</span>
            </summary>
            <div class="border-t border-slate-100">
    <x-admin.panel flush :title="'Belum ditutup setelah shift berakhir ('.($load->failed('unclosed') ? '-' : $unclosed['total']).')'" description="Semua tanggal, terlama lebih dulu. Status sesi tetap Dibuka; sistem tidak menutup sesi otomatis. Penutupan dari HP yang masih offline tetap diterima dan ditandai “Ditutup setelah shift berakhir”.">
        <div data-unclosed-panel="{{ $load->failed('unclosed') ? 'GAGAL' : $unclosed['total'] }}">
        @if ($load->failed('unclosed'))
            <x-admin.error-state />
        @elseif ($unclosed['total'] === 0)
            <div class="px-4 py-3 text-xs text-slate-500">Tidak ada sesi PASILA yang melewati akhir shift tanpa penutupan{{ $filters['posko_id'] !== '' ? ' untuk Posko ini' : '' }}.</div>
        @else
            <x-admin.table min-width="min-w-[900px]">
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th>
                    <th class="px-4 py-3">Sesi PASILA</th>
                    <th class="px-4 py-3">Shift berakhir</th>
                    <th class="px-4 py-3">Keterlambatan</th>
                    <th class="px-4 py-3">Laporan patroli</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </x-slot:head>
                @foreach ($unclosed['sessions'] as $late)
                    @php $minutes = $late->minutesUnclosedAfterShift(); @endphp
                    <tr data-unclosed-session="{{ $late->code }}" data-unclosed-minutes="{{ $minutes }}">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $late->posko?->nama_posko ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <x-admin.status-badge tone="amber">{{ $late->statusLabel() }}</x-admin.status-badge>
                            <div class="mt-1 text-[10px] font-semibold text-slate-700">{{ $late->code }}</div>
                            <div class="text-[10px] text-slate-500">Shift {{ $late->shiftInstance->shift_code }} · {{ $late->shiftInstance->operational_date->format('d/m/Y') }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700">{{ $fmt($late->shiftInstance->ends_at) }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold {{ $minutes >= 1440 ? 'text-red-700' : 'text-amber-700' }}">{{ PasilaSession::durationLabel($minutes) }}</span>
                            <div class="text-[10px] text-slate-500">belum ada penutupan diterima server</div>
                        </td>
                        <td class="px-4 py-3">
                            <x-admin.status-badge :tone="$patrolTone[$late->patrolStatus()]">{{ $late->patrolStatusLabel() }}</x-admin.status-badge>
                            <div class="mt-1 text-[10px] text-slate-500">{{ $late->findings_count }} temuan</div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('admin.pasila.show', ['posko' => $late->posko_id, 'work_date' => $late->shiftInstance->operational_date->toDateString(), 'shift_code' => $late->shiftInstance->shift_code]) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            @if ($unclosed['total'] > $unclosed['sessions']->count())
                <div class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">Menampilkan {{ $unclosed['sessions']->count() }} terlama dari {{ $unclosed['total'] }} sesi.</div>
            @endif
        @endif
        </div>
    </x-admin.panel>
            </div>
        </details>
        <details class="group min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-4 font-semibold text-slate-800 marker:hidden">
                <span>Referensi Section & kualitas data <span class="ml-1 text-xs font-normal text-slate-500">{{ $heldEntries->count() }} ditahan</span></span>
                <span class="text-xs text-blue-600 group-open:hidden">Buka +</span><span class="hidden text-xs text-blue-600 group-open:inline">Tutup âˆ’</span>
            </summary>
            <div class="border-t border-slate-100">
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
        </details>
    </div>
</div>
@endsection
