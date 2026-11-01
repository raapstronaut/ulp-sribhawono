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
            @if ($session)
                <x-admin.button href="#p0">Buat P0 dari PASILA</x-admin.button>
            @else
                <x-admin.unavailable-action label="Buat P0 dari PASILA" reason="P0 wajib dibuat dari sesi PASILA yang valid pada Section dan Posko yang sama; sesi PASILA shift ini belum dibuka." />
            @endif
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

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-admin.unavailable-panel
            title="Eskalasi & Pekerjaan Tertunda"
            description="Permintaan eskalasi dari temuan PASILA dan tindakan yang tertunda akan tampil setelah alur eskalasi tersedia."
            :requirements="['Tabel work_escalations', 'Keputusan Admin melalui Review & Validasi']"
        />
    </div>
</div>
@endsection
