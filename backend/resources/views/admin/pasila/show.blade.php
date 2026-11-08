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
    $pasilaInitialErrorTab = $errors->has('section') || $errors->has('reason') ? 'section' : ($errors->any() ? 'p0' : 'ringkasan');
@endphp
<div class="mx-auto max-w-7xl space-y-3" data-pasila-detail-root>
    <x-admin.posko-tabs />
    <x-admin.page-header
        section="Pekerjaan"
        parent="PASILA"
        :parent-url="route('admin.pasila.index', $filters)"
        :title="$posko->nama_posko"
        state="partial"
        :description="'Shift '.$context['shift']['code'].' ('.$context['shift']['name'].', '.$context['shift']['time'].') hari operasional '.$workDateLabel.'. Status sesi PASILA, status WO APKUK, dan kewajiban P0 ditampilkan terpisah.'"
    >
        <x-slot:actions>
            @if ($session)
                <x-admin.button href="#p0">Buat P0 dari PASILA</x-admin.button>
            @else
                <x-admin.unavailable-action label="Buat P0 dari PASILA" reason="P0 wajib dibuat dari sesi PASILA yang valid pada Section dan Posko yang sama; sesi PASILA shift ini belum dibuka." />
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- PASILA DETAIL: ringkas, seluruh status berasal dari data server. --}}
    <section data-pasila-detail-v2 class="rounded-xl border border-blue-200 bg-blue-50 p-3 shadow-sm sm:p-4" aria-label="Ringkasan PASILA per POSKO">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-700">
                @if ($context['is_current'])
                    <span class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-700">Shift berjalan</span>
                @else
                    <span class="rounded-md border border-slate-200 bg-white px-2 py-1 text-slate-600">Riwayat shift</span>
                @endif
                <span>Shift {{ $context['shift']['code'] }} · {{ $context['shift']['name'] }} · {{ $workDateLabel }}</span>
            </div>
            <a href="{{ route('admin.pasila.index', $filters) }}" class="rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">Kembali ke Monitor</a>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Sesi PASILA</div>
                <div class="mt-1 text-base font-bold text-blue-700">
                    @if ($load->failed('sessions')) Gagal dimuat
                    @else {{ $session?->statusLabel() ?? 'Belum dibuka' }} @endif
                </div>
                <div class="mt-1 break-all text-xs text-slate-500">{{ $session?->code ?? 'Belum ada sesi untuk shift ini' }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Section patroli</div>
                <div class="mt-1 text-base font-bold text-slate-900">
                    @if ($load->failed('sections')) Gagal dimuat
                    @else {{ $session?->section_name ?? 'Belum ditentukan' }} @endif
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ $session?->penyulang?->nama_penyulang ?? 'Penyulang belum tersedia' }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Laporan patroli</div>
                <div class="mt-1 text-base font-bold {{ $session && ! $load->failed('findings', 'nil_report') && $session->patrolStatus() !== PasilaSession::PATROL_BELUM ? 'text-emerald-700' : 'text-amber-700' }}">
                    @if (! $session) Belum ada sesi
                    @elseif ($load->failed('findings', 'nil_report')) Gagal dimuat
                    @else {{ $session->patrolStatusLabel() }} @endif
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ ! $load->failed('findings') && $session ? $findings->count().' temuan tercatat' : 'Belum dapat dihitung' }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Target P0</div>
                <div class="mt-1 text-base font-bold {{ $session && $p0Obligation['fulfilled'] ? 'text-emerald-700' : 'text-amber-700' }}">
                    {{ $session ? $p0Obligation['completed'].' / '.$p0Obligation['target'].' selesai' : 'Belum ada sesi' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">Indikator P0 terpisah dari status patroli</div>
            </div>
        </div>
    </section>

    <div id="pasila-detail-filter" class="scroll-mt-6"></div>
    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.pasila.show', $posko)" :reset-url="route('admin.pasila.show', $posko)" :active="request()->hasAny(['work_date', 'shift_code'])">
            <x-admin.filter-input type="date" name="work_date" label="Tanggal operasional" :value="$filters['work_date']" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$filters['shift_code']" :allow-empty="false" />
        </x-admin.filter-bar>
        <details class="border-t border-slate-100 px-3 py-2">
            <summary class="cursor-pointer select-none text-xs font-semibold text-slate-600 hover:text-blue-700">Detail shift dan POSKO</summary>
            <dl class="grid grid-cols-2 gap-3 pt-3 text-sm md:grid-cols-4">
            <x-admin.detail-item label="Kode Posko">{{ $posko->kode_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Status master">{{ $posko->is_active ? 'Aktif' : 'Nonaktif' }}</x-admin.detail-item>
            <x-admin.detail-item label="Shift">{{ 'Shift '.$context['shift']['code'].' · '.$context['shift']['name'] }}{{ $context['is_current'] ? ' (berjalan)' : '' }}</x-admin.detail-item>
            <x-admin.detail-item label="Jendela shift">{{ $fmt($context['window']['start']) }} – {{ $fmt($context['window']['end']) }}</x-admin.detail-item>
            </dl>
        </details>
    </x-admin.panel>

    <style>
        [data-pasila-panel][hidden] { display: none !important; }
        [data-pasila-tab][aria-selected="true"] {
            background: #2563eb;
            border-color: #2563eb;
            color: white;
        }
        [data-pasila-tab]:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; }
    </style>
    <nav role="tablist" aria-label="Bagian detail PASILA" class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2" data-pasila-tablist>
        <button type="button" id="pasila-tab-ringkasan" role="tab" data-pasila-tab="ringkasan"
            aria-controls="pasila-panel-ringkasan" aria-selected="true" tabindex="0"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">Ringkasan</button>
        <button type="button" id="pasila-tab-section" role="tab" data-pasila-tab="section"
            aria-controls="pasila-panel-section" aria-selected="false" tabindex="-1"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">Section & Petugas</button>
        <button type="button" id="pasila-tab-laporan" role="tab" data-pasila-tab="laporan"
            aria-controls="pasila-panel-laporan" aria-selected="false" tabindex="-1"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">Laporan</button>
        <button type="button" id="pasila-tab-p0" role="tab" data-pasila-tab="p0"
            aria-controls="pasila-panel-p0" aria-selected="false" tabindex="-1"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">P0</button>
        <button type="button" id="pasila-tab-wo" role="tab" data-pasila-tab="wo"
            aria-controls="pasila-panel-wo" aria-selected="false" tabindex="-1"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">WO & Reschedule</button>
        <button type="button" id="pasila-tab-audit" role="tab" data-pasila-tab="audit"
            aria-controls="pasila-panel-audit" aria-selected="false" tabindex="-1"
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50">Audit & Referensi</button>
    </nav>

    <section id="pasila-panel-ringkasan" role="tabpanel" aria-labelledby="pasila-tab-ringkasan" tabindex="0"
        data-pasila-panel="ringkasan" class="space-y-3">
    <x-admin.panel title="Sesi PASILA shift ini" description="Satu sesi per Posko per shift. Status sesi terpisah dari status WO APKUK dan kewajiban P0.">
        @if ($load->failed('sessions'))
            <x-admin.error-state :inset="false" />
        @elseif ($session)
            <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4" data-session="{{ $session->code }}">
                <x-admin.detail-item label="Kode sesi">{{ $session->code }}</x-admin.detail-item>
                @php $unclosedLabel = $session->unclosedAfterShiftLabel(); @endphp
                <x-admin.detail-item label="Status sesi">
                    <x-admin.status-badge :tone="$session->isClosed() ? 'emerald' : ($unclosedLabel ? 'amber' : 'blue')">{{ $session->statusLabel() }}</x-admin.status-badge>
                    @if ($unclosedLabel)
                        <span class="mt-1 block text-[11px] font-semibold text-amber-700" data-unclosed-label>{{ $unclosedLabel }}</span>
                    @endif
                </x-admin.detail-item>
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
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Belum ada sesi PASILA.</strong>
                {{ $noSession[$context['phase']] }}
            </div>
        @endif
    </x-admin.panel>
    </section>

    <section id="pasila-panel-section" role="tabpanel" aria-labelledby="pasila-tab-section" tabindex="0"
        data-pasila-panel="section" hidden class="space-y-3">
    {{-- PASILA_CREW_PREVIEW_V1: hanya baca, bukan pengaktifan penugasan. --}}
    <x-admin.panel title="Pratinjau pasangan POSKO" description="Berdasarkan tanggal operasional dan shift terpilih. Tidak mengubah sesi PASILA, WO, atau akun Android.">
        @if ($load->failed('posko_crew_preview'))
            <x-admin.error-state :inset="false" />
        @elseif (($crewPreview['status'] ?? '') === 'ready')
            <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><div class="text-xs text-slate-500">Pasangan terjadwal</div><div class="font-bold text-slate-900">TIM {{ $crewPreview['crew_number'] }}</div></div>
                <div><div class="text-xs text-slate-500">Hari rotasi</div><div class="font-bold text-slate-900">{{ $crewPreview['cycle_day'] }} / 4</div></div>
                <div><div class="text-xs text-slate-500">Pasangan libur</div><div class="font-bold text-slate-900">TIM {{ $crewPreview['off_crew_number'] }}</div></div>
            </div>
            <ul class="mt-3 space-y-1 text-sm text-slate-800" data-posko-crew="{{ $crewPreview['crew_number'] }}">
                @foreach ($crewPreview['members'] as $member)
                    <li class="font-medium">{{ $member['name'] }} @if ($member['nickname'] && $member['nickname'] !== $member['name']) <span class="text-xs font-normal text-slate-500">({{ $member['nickname'] }})</span> @endif</li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-amber-700">Ini pratinjau roster, bukan bukti penerimaan tugas, laporan patroli, atau penugasan otomatis.</p>
        @else
            <p class="text-sm text-slate-600">{{ $crewPreview['message'] ?? 'Pratinjau belum tersedia.' }}</p>
        @endif
    </x-admin.panel>


    <div id="pasila-section" class="scroll-mt-6">
        <h2 class="mb-2 text-base font-bold text-slate-900">Penugasan Section</h2>
        <p class="mb-3 text-xs text-slate-500">Section hanya dapat ditetapkan dari referensi terverifikasi milik POSKO ini. Perubahan oleh Admin tetap dicatat dalam Audit Log.</p>
    </div>
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
    </section>

    <section id="pasila-panel-laporan" role="tabpanel" aria-labelledby="pasila-tab-laporan" tabindex="0"
        data-pasila-panel="laporan" hidden class="space-y-3">
    <div id="pasila-laporan" class="scroll-mt-6">
        <h2 class="mb-2 text-base font-bold text-slate-900">Laporan dan penutupan patroli</h2>
        <p class="mb-3 text-xs text-slate-500">Belum ada temuan bukan berarti nihil. Laporan nihil harus dikirim petugas; sesi ditutup dari aplikasi POSKO.</p>
    </div>
    <x-admin.panel flush title="Laporan patroli & temuan per titik" description="Dicatat petugas POSKO lewat aplikasi: satu record per titik dengan Section, kode, uraian, GPS perangkat, waktu, foto, dan petugas sendiri. Daftar kosong tanpa laporan nihil berarti belum ada laporan patroli, bukan nihil.">
        @if (! $session)
            <div class="p-4 text-xs text-slate-500">Tersedia setelah sesi PASILA shift ini dibuka.</div>
        @elseif ($load->failed('findings', 'nil_report'))
            <x-admin.error-state />
        @else
            @php $patrol = $session->patrolStatus(); @endphp
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 text-xs" data-patrol-status="{{ $patrol }}">
                <x-admin.status-badge :tone="[PasilaSession::PATROL_BELUM => 'slate', PasilaSession::PATROL_ADA_TEMUAN => 'violet', PasilaSession::PATROL_NIHIL => 'emerald'][$patrol]">{{ $session->patrolStatusLabel() }}</x-admin.status-badge>
                <span class="text-slate-600">{{ $findings->count() }} temuan tercatat</span>
                @if ($findingCodeLabels === [])
                    <span class="text-amber-800">Kode temuan PASILA belum diisi di Master Data (kategori PASILA_FINDING); petugas belum dapat mengirim temuan.</span>
                @endif
            </div>

            <div class="border-b border-slate-100 px-4 py-3 text-xs" data-closure-panel="{{ $session->closure?->outcome ?? 'BELUM_DITUTUP' }}">
                @if ($closure = $session->closure)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-admin.status-badge tone="emerald">Sesi ditutup · {{ $closure->outcomeLabel() }}</x-admin.status-badge>
                        <span class="text-slate-600">{{ $fmt($closure->occurred_at) }} · diterima {{ $fmt($closure->received_at) }} · Akun {{ $closure->submittedByTeam?->code ?? '-' }} ({{ $closure->submittedBy?->username ?? '-' }}) · Petugas: {{ collect($closure->personnel)->pluck('name')->implode(', ') }}</span>
                    </div>
                    <p class="mt-1 text-slate-500" data-closure-snapshot>Saat ditutup: {{ $closure->finding_count }} temuan · target P0 {{ ($closure->snapshot['p0']['fulfilled'] ?? false) ? 'terpenuhi' : 'belum terpenuhi' }} ({{ $closure->snapshot['p0']['completed'] ?? 0 }}/{{ $closure->snapshot['p0']['target'] ?? 1 }} selesai) · WO APKUK {{ $closure->snapshot['apkuk']['belum_selesai'] ?? 0 }} belum selesai, {{ $closure->snapshot['apkuk']['reschedule_menunggu_acc'] ?? 0 }} menunggu ACC reschedule. Status terkini ditampilkan di bawah dan dapat berbeda.</p>
                    @if ($closure->note)
                        <p class="mt-1 text-slate-700">{{ $closure->note }}</p>
                    @endif
                    @foreach ($session->closureFlags() as $flag => $text)
                        <p class="mt-1 font-semibold text-amber-700" data-closure-flag="{{ $flag }}">{{ $text }}</p>
                    @endforeach
                @else
                    @if (($minutes = $session->minutesUnclosedAfterShift()) !== null)
                        <p class="mb-1 font-semibold text-amber-700" data-unclosed-minutes="{{ $minutes }}">Shift berakhir {{ $fmt($session->shiftInstance->ends_at) }}; belum ada penutupan yang diterima server selama {{ PasilaSession::durationLabel($minutes) }}. Status tetap Dibuka dan sistem tidak menutup otomatis. Bila HP petugas masih offline, penutupan yang terkirim kemudian tetap diterima dan ditandai “Ditutup setelah shift berakhir”.</p>
                    @endif
                    <span class="text-slate-500">Sesi belum ditutup. Penutupan dilakukan tim POSKO lewat aplikasi setelah laporan patroli (temuan atau laporan nihil) diterima; tidak menunggu target P0 maupun reschedule WO APKUK.</span>
                @endif
            </div>

            @if ($nilReport)
                <div class="border-b border-slate-100 px-4 py-3 text-xs" data-nil-report="{{ $nilReport->status }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-admin.status-badge :tone="$nilReport->isActive() ? 'emerald' : 'slate'">Laporan nihil · {{ $nilReport->statusLabel() }}</x-admin.status-badge>
                        <span class="text-slate-600">{{ $fmt($nilReport->occurred_at) }} · diterima {{ $fmt($nilReport->received_at) }}</span>
                        @if (! $nilReport->isActive() && $nilReport->supersededBy)
                            <span class="font-semibold text-violet-700">Digantikan temuan #{{ $nilReport->supersededBy->sequence }} ({{ $fmt($nilReport->superseded_at) }})</span>
                        @endif
                    </div>
                    <p class="mt-1 text-slate-700">{{ $nilReport->note }}</p>
                    <p class="mt-1 text-slate-500">GPS {{ number_format((float) $nilReport->latitude, 6) }}, {{ number_format((float) $nilReport->longitude, 6) }}{{ $nilReport->gps_accuracy_m !== null ? ' (±'.(float) $nilReport->gps_accuracy_m.' m)' : '' }} · Petugas: {{ $nilReport->personnel->pluck('personnel_name')->implode(', ') }} · Akun {{ $nilReport->submittedBy?->username ?? '-' }}</p>
                </div>
            @endif

            @if ($findings->isEmpty())
                <x-admin.empty-state :title="$nilReport ? 'Tidak ada temuan.' : 'Belum ada laporan patroli.'" :description="$nilReport ? 'Petugas menyatakan patroli dilakukan tanpa temuan.' : 'Belum ada temuan maupun laporan nihil yang diterima untuk sesi ini (patroli belum dilaporkan atau kiriman offline belum masuk).'" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($findings as $finding)
                        <li class="space-y-2 p-4" data-finding="{{ $finding->sequence }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-slate-900">#{{ $finding->sequence }}</span>
                                <x-admin.status-badge tone="violet">{{ $finding->finding_code }}</x-admin.status-badge>
                                <span class="text-xs font-semibold text-slate-800">{{ $findingCodeLabels[$finding->finding_code] ?? 'Kode tidak ada lagi di Master Data' }}</span>
                                <x-admin.status-badge tone="blue">{{ $finding->statusLabel() }}</x-admin.status-badge>
                            </div>
                            <p class="text-sm text-slate-700">{{ $finding->description }}</p>
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-slate-600 md:grid-cols-2">
                                <div><span class="font-semibold text-slate-700">Section:</span> {{ $finding->section_name }} ({{ $finding->penyulang?->nama_penyulang ?? '-' }})
                                    @if (isset($findingSectionNotes[$finding->id]))
                                        <span class="block font-semibold text-red-700">{{ $findingSectionNotes[$finding->id] }}</span>
                                    @endif
                                </div>
                                <div><span class="font-semibold text-slate-700">Aset:</span> {{ $finding->keypoint ? $finding->keypoint->nama.' ('.$finding->keypoint->jenis.')' : ($finding->asset_label ?? '-') }}</div>
                                <div data-finding-gps><span class="font-semibold text-slate-700">GPS perangkat:</span>
                                    <a href="https://www.openstreetmap.org/?mlat={{ $finding->latitude }}&amp;mlon={{ $finding->longitude }}#map=18/{{ $finding->latitude }}/{{ $finding->longitude }}" target="_blank" rel="noopener" class="font-semibold text-blue-600 hover:text-blue-800">{{ number_format((float) $finding->latitude, 6) }}, {{ number_format((float) $finding->longitude, 6) }}</a>{{ $finding->gps_accuracy_m !== null ? ' (±'.(float) $finding->gps_accuracy_m.' m)' : '' }}
                                </div>
                                <div><span class="font-semibold text-slate-700">Waktu kejadian:</span> {{ $fmt($finding->occurred_at) }} · diterima {{ $fmt($finding->received_at) }}
                                    @if ($finding->received_at->gt($session->shiftInstance->ends_at))
                                        <span class="text-amber-700">(terkirim setelah shift berakhir)</span>
                                    @endif
                                </div>
                                <div data-finding-reporter><span class="font-semibold text-slate-700">Petugas:</span> {{ $finding->personnel->pluck('personnel_name')->implode(', ') }} · Akun {{ $finding->submittedByTeam?->code ?? '-' }} ({{ $finding->submittedBy?->username ?? '-' }})</div>
                            </dl>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($finding->media as $photo)
                                    <a href="{{ route('admin.media.pasila', $photo) }}" target="_blank" rel="noopener"><img src="{{ route('admin.media.pasila', $photo) }}" alt="Foto temuan #{{ $finding->sequence }}" loading="lazy" class="h-20 w-24 rounded border border-slate-200 object-cover"></a>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-admin.panel>
    </section>

    <section id="pasila-panel-p0" role="tabpanel" aria-labelledby="pasila-tab-p0" tabindex="0"
        data-pasila-panel="p0" hidden class="space-y-3">
    <div class="scroll-mt-6">
        <h2 class="mb-2 text-base font-bold text-slate-900">Pekerjaan P0</h2>
        <p class="mb-3 text-xs text-slate-500">P0 dihitung berdasarkan pekerjaan yang selesai, bukan sekadar usulan atau sesi PASILA yang dibuka.</p>
    </div>
    <x-admin.panel flush title="P0 sesi ini" description="P0 POSKO dari sumber masalah yang tercatat (temuan PASILA atau temuan inspeksi tervalidasi) pada Section milik Posko ini. Usulan SYSTEM perlu ACC; usulan ditolak wajib diganti P0 manual. Target terpenuhi hanya oleh P0 yang selesai.">
        <div id="p0"></div>
        @if (! $session)
            <div class="p-4 text-xs text-slate-500">Tersedia setelah sesi PASILA shift ini dibuka.</div>
        @else
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 text-xs" data-p0-obligation="{{ $p0Obligation['fulfilled'] ? 'TERPENUHI' : 'BELUM' }}">
                <x-admin.status-badge :tone="$p0Obligation['fulfilled'] ? 'emerald' : 'amber'">Target P0 shift: {{ $p0Obligation['fulfilled'] ? 'terpenuhi' : 'belum terpenuhi' }}</x-admin.status-badge>
                <span class="text-slate-600">{{ $p0Obligation['completed'] }}/{{ $p0Obligation['target'] }} P0 selesai</span>
                <x-admin.status-badge :tone="$p0Obligation['replacement_open'] > 0 ? 'red' : 'slate'" data-p0-replacement-open="{{ $p0Obligation['replacement_open'] }}">Kewajiban pengganti Admin: {{ $p0Obligation['replacement_open'] > 0 ? $p0Obligation['replacement_open'].' terbuka' : 'tidak ada' }}</x-admin.status-badge>
                @if (! $p0Obligation['fulfilled'] && $p0Items->isEmpty())
                    <span class="text-amber-800">Belum ada P0 untuk sesi ini.</span>
                @endif
                <span class="text-slate-500">Usulan SYSTEM otomatis: {{ $p0Strategy->notReadyReason() ? 'belum siap' : $p0Strategy->name() }}</span>
            </div>
            @if ($p0Items->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach ($p0Items as $item)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs" data-p0-item="{{ $item->code }}" data-stage="{{ $item->stage() }}">
                            <div>
                                <a href="{{ route('admin.p0.show', $item) }}" class="font-bold text-blue-600 hover:text-blue-800">{{ $item->code }}</a>
                                <span class="text-slate-600">· {{ $item->originLabel() }} · {{ $item->sourceLabel() }} · {{ $item->section_name }}</span>
                                <div class="text-slate-700">{{ $item->description }}</div>
                            </div>
                            <x-admin.status-badge :tone="$item->stage() === \App\Models\P0Item::STAGE_SELESAI ? 'emerald' : ($item->stage() === \App\Models\P0Item::STAGE_MENUNGGU_PENGGANTI ? 'red' : 'blue')">{{ $item->stageLabel() }}</x-admin.status-badge>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="border-t border-slate-100 p-4">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">Buat P0 manual</h3>
                @if ($load->failed('p0_sources'))
                    <x-admin.error-state :inset="false" />
                @elseif ($p0SourceOptions === [])
                    <x-admin.empty-state title="Belum ada sumber P0 yang sah." description="P0 hanya dibuat dari temuan PASILA milik Posko ini atau temuan inspeksi yang sudah divalidasi pada Section milik Posko ini, yang belum memiliki P0 berjalan. Tidak ada P0 yang dibuat tanpa sumber; kewajiban P0 tetap tampil belum terpenuhi." />
                @else
                    <form method="POST" action="{{ route('admin.pasila.p0.store', $session) }}" class="grid grid-cols-1 gap-3 md:grid-cols-2" data-p0-form>
                        @csrf
                        <div class="md:col-span-2"><x-admin.select name="source" label="Sumber masalah" :options="$p0SourceOptions" placeholder="Pilih sumber yang tercatat" required /></div>
                        <div class="md:col-span-2"><x-admin.textarea name="description" label="Uraian pekerjaan P0" required hint="Minimal 10 karakter: tindakan yang harus dikerjakan POSKO." /></div>
                        <x-admin.input type="date" name="work_date" label="Tanggal operasional" :value="$p0Schedule['work_date']" required />
                        <x-admin.select name="shift_code" label="Shift" :options="$shiftOptions" :value="$p0Schedule['shift_code']" required />
                        @if ($p0ReplaceOptions !== [])
                            <div class="md:col-span-2"><x-admin.select name="replaces_p0_item_id" label="Sebagai pengganti usulan yang ditolak" :options="$p0ReplaceOptions" placeholder="Bukan pengganti" /></div>
                        @endif
                        <div class="md:col-span-2"><x-admin.button>Buat P0 dan tugaskan ke POSKO</x-admin.button></div>
                    </form>
                @endif
            </div>
        @endif
    </x-admin.panel>
    </section>

    <section id="pasila-panel-wo" role="tabpanel" aria-labelledby="pasila-tab-wo" tabindex="0"
        data-pasila-panel="wo" hidden class="space-y-3">
    <details id="pasila-wo" class="group scroll-mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="cursor-pointer list-none px-4 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50">WO APKUK pada shift ini <span class="ml-2 text-xs font-normal text-slate-500">Target {{ $targetPerShift }} WO per shift</span><span class="float-right text-xs font-semibold text-blue-600 group-open:hidden">Buka +</span><span class="float-right hidden text-xs font-semibold text-blue-600 group-open:inline">Tutup -</span></summary>
        <div class="border-t border-slate-100 p-3">
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
        </div>
    </details>

    <details class="group rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="cursor-pointer list-none px-4 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50">Pengajuan reschedule <span class="float-right text-xs font-semibold text-blue-600 group-open:hidden">Buka +</span><span class="float-right hidden text-xs font-semibold text-blue-600 group-open:inline">Tutup -</span></summary>
        <div class="border-t border-slate-100 p-3">
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
        </div>
    </details>

    <details class="group rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="cursor-pointer list-none px-4 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50">Fitur lanjutan dan eskalasi <span class="float-right text-xs font-semibold text-blue-600 group-open:hidden">Buka +</span><span class="float-right hidden text-xs font-semibold text-blue-600 group-open:inline">Tutup -</span></summary>
        <div class="grid grid-cols-1 gap-4 border-t border-slate-100 p-3 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Eskalasi & Pekerjaan Tertunda"
            description="Permintaan eskalasi dari temuan PASILA dan tindakan yang tertunda akan tampil setelah alur eskalasi tersedia."
            :requirements="['Tabel work_escalations', 'Keputusan Admin melalui Review & Validasi']"
        />
        </div>
    </details>
    </section>

    <section id="pasila-panel-audit" role="tabpanel" aria-labelledby="pasila-tab-audit" tabindex="0"
        data-pasila-panel="audit" hidden class="space-y-3">
    <details id="pasila-referensi" class="group scroll-mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
        <summary class="cursor-pointer list-none px-4 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50">Referensi POSKO &amp; tim lapangan <span class="float-right text-xs font-semibold text-blue-600 group-open:hidden">Buka +</span><span class="float-right hidden text-xs font-semibold text-blue-600 group-open:inline">Tutup -</span></summary>
        <div class="grid grid-cols-1 gap-4 border-t border-slate-100 p-3 lg:grid-cols-2">
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
    </details>


    {{-- AUDIT_PELAPOR_PASILA_V1: perbandingan informatif, tidak menolak laporan. --}}
    <x-admin.panel title="Audit kesesuaian pelapor PASILA" description="Membandingkan petugas tercatat dengan pasangan pada tanggal/shift sesi. Tidak mengubah laporan, penugasan, atau status PASILA.">
        @if (! $session)
            <p class="text-sm text-slate-600">Belum ada sesi PASILA untuk tanggal dan shift ini; belum ada laporan untuk diaudit.</p>
        @elseif ($load->failed('pasila_crew_report_review'))
            <x-admin.error-state :inset="false" />
        @else
            @if (($crewReportReview['schedule']['status'] ?? '') === 'ready')
                <p class="text-sm font-semibold text-slate-800">Jadwal: TIM {{ $crewReportReview['schedule']['crew_number'] }} ({{ collect($crewReportReview['schedule']['members'])->pluck('name')->implode(' dan ') }})</p>
            @else
                <p class="text-sm font-semibold text-amber-700">Jadwal belum bisa diverifikasi: {{ $crewReportReview['schedule']['message'] ?? 'Data belum lengkap' }}</p>
            @endif
            <p class="mt-1 text-xs text-slate-500">Waktu acuan adalah tanggal operasional dan shift SESI, bukan waktu laporan diterima. Perbandingan memakai roster aktif saat ini; jika ada perubahan roster historis, hasil perlu ditinjau manual.</p>
            @if (empty($crewReportReview['rows']))
                <p class="mt-3 text-sm text-slate-600">Belum ada temuan, laporan nihil, atau penutupan sesi yang perlu dibandingkan.</p>
            @else
                <div class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                    <div>Sesuai: <strong>{{ $crewReportReview['counts']['match'] }}</strong></div>
                    <div>Sebagian: <strong>{{ $crewReportReview['counts']['partial'] }}</strong></div>
                    <div>Perlu tinjauan: <strong>{{ $crewReportReview['counts']['different'] }}</strong></div>
                    <div>Belum terverifikasi: <strong>{{ $crewReportReview['counts']['unverifiable'] }}</strong></div>
                </div>
                <x-admin.table min-width="min-w-[820px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Laporan</th>
                        <th class="px-4 py-3">Petugas tercatat</th>
                        <th class="px-4 py-3">Hasil audit</th>
                        <th class="px-4 py-3">Catatan</th>
                    </x-slot:head>
                    @foreach ($crewReportReview['rows'] as $reviewRow)
                        <tr>
                            <td class="px-4 py-3 text-xs font-semibold">{{ $reviewRow['label'] }}</td>
                            <td class="px-4 py-3 text-xs">{{ implode(', ', $reviewRow['personnel_names']) ?: '-' }}</td>
                            <td class="px-4 py-3"><x-admin.status-badge :tone="['match' => 'emerald', 'partial' => 'amber', 'different' => 'red', 'unverifiable' => 'slate'][$reviewRow['status']]">{{ $reviewRow['status_label'] }}</x-admin.status-badge></td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $reviewRow['note'] }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <p class="mt-2 text-xs text-amber-700">Perbedaan pasangan bukan bukti pelanggaran. Penggantian petugas dan laporan offline harus tetap dapat ditinjau Admin.</p>
            @endif
        @endif
    </x-admin.panel>
    </section>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const root = document.querySelector('[data-pasila-detail-root]');
            if (!root) return;

            const tabs = Array.from(root.querySelectorAll('[data-pasila-tab]'));
            const panels = Array.from(root.querySelectorAll('[data-pasila-panel]'));
            const tabNames = tabs.map(tab => tab.dataset.pasilaTab);
            const hashTabs = {
                '#pasila-section': 'section',
                '#pasila-laporan': 'laporan',
                '#p0': 'p0',
                '#pasila-wo': 'wo',
                '#pasila-referensi': 'audit'
            };

            function activate(name, focus = false) {
                const chosen = tabNames.includes(name) ? name : 'ringkasan';
                tabs.forEach(tab => {
                    const selected = tab.dataset.pasilaTab === chosen;
                    tab.setAttribute('aria-selected', String(selected));
                    tab.tabIndex = selected ? 0 : -1;
                    if (selected && focus) tab.focus();
                });
                panels.forEach(panel => {
                    panel.hidden = panel.dataset.pasilaPanel !== chosen;
                });
            }

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => activate(tab.dataset.pasilaTab));
                tab.addEventListener('keydown', event => {
                    let next = index;
                    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                    else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                    else if (event.key === 'Home') next = 0;
                    else if (event.key === 'End') next = tabs.length - 1;
                    else return;
                    event.preventDefault();
                    activate(tabs[next].dataset.pasilaTab, true);
                });
            });

            function openAnchor(hash, shouldScroll = false) {
                const tab = hashTabs[hash];
                if (!tab) return false;
                activate(tab);
                if (shouldScroll) {
                    const target = document.getElementById(hash.slice(1));
                    if (target) requestAnimationFrame(() => target.scrollIntoView({block: 'start'}));
                }
                return true;
            }

            document.addEventListener('click', event => {
                if (!(event.target instanceof Element)) return;
                const anchor = event.target.closest('a[href^="#"]');
                if (anchor) openAnchor(anchor.getAttribute('href'));
            });
            window.addEventListener('hashchange', () => openAnchor(window.location.hash, true));

            const formErrorTab = @json($pasilaInitialErrorTab);
            const pageParam = new URLSearchParams(window.location.search).has('page') ? 'wo' : null;
            const start = hashTabs[window.location.hash] || pageParam || formErrorTab;
            activate(start);
            if (hashTabs[window.location.hash]) openAnchor(window.location.hash, true);
        });
    </script>

@endsection
