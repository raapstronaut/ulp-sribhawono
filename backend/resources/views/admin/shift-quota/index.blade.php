@extends('layouts.admin')

@section('title', 'Kuota Shift APKUK')

@section('content')
@php
    use App\Models\ApkukGenerationRun;
    use App\Services\Apkuk\Generation\ApkukShiftGenerator;
    use App\Services\Apkuk\SectionPoskoMap;
    use App\Services\ApkukCandidateService;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $totals = [
        'carry' => $rows->sum(fn ($r) => $r['carry_over']->count()),
        'manual' => $rows->sum(fn ($r) => $r['manual']->count()),
        'generated' => $rows->sum(fn ($r) => $r['generated']->count()),
        'open' => $rows->sum('open_slots'),
        'overload' => $rows->sum('overload'),
    ];
    $statusTone = [ApkukGenerationRun::STATUS_COMPLETED => 'emerald', ApkukGenerationRun::STATUS_REFUSED => 'amber', ApkukGenerationRun::STATUS_FAILED => 'red'];
    $poskoStatus = [ApkukShiftGenerator::POSKO_PREVIEW => 'Pratinjau', ApkukShiftGenerator::POSKO_GENERATED => 'Digenerate', ApkukShiftGenerator::POSKO_ALREADY_GENERATED => 'Sudah digenerate sebelumnya'];
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" title="Kuota Shift APKUK" state="partial"
        :description="'Target '.$target.' WO pemasangan APKUK per Posko per shift (6 pekerjaan, bukan 6 unit). Shift '.$code.' hari operasional '.\Illuminate\Support\Carbon::parse($date)->format('d/m/Y').': '.$fmt($window['start']).' – '.$fmt($window['end']).($isCurrent ? ' (berjalan)' : '')">
        <x-slot:actions>
            <x-admin.status-badge tone="slate">Lingkungan: {{ $environment }}</x-admin.status-badge>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.notice tone="warning" title="Belum operasional.">
        Generator otomatis {{ $blockedReason ? 'nonaktif' : 'aktif untuk lingkungan ini' }}.
        Strategi saat ini {{ $ranking->name() }} {{ $ranking->version() }} adalah <strong>baseline rule-based, bukan machine learning</strong>.
        Generate hanya dapat berjalan di lingkungan pengembangan/pengujian (local, testing) dengan aktivasi eksplisit; lingkungan lain, termasuk production dan Neon, selalu ditolak sampai model ML yang terlatih dan tervalidasi serta prosedur readiness tersedia. <strong>Pratinjau</strong> hanya simulasi dan tidak membuat WO.
        Kandidat yang Section–Posko-nya tidak terbukti ditahan, bukan ditebak.
    </x-admin.notice>

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.shift-quota.index')" :reset-url="route('admin.shift-quota.index')" :active="request()->hasAny(['operational_date', 'shift_code'])">
            <x-admin.filter-input type="date" name="operational_date" label="Tanggal operasional" :value="$date" />
            <x-admin.filter-select name="shift_code" label="Shift" :options="$shiftOptions" :value="$code" :allow-empty="false" />
        </x-admin.filter-bar>
        <div class="flex flex-wrap items-center gap-3 p-4">
            <form method="POST" action="{{ route('admin.shift-quota.run') }}">
                @csrf
                <input type="hidden" name="operational_date" value="{{ $date }}">
                <input type="hidden" name="shift_code" value="{{ $code }}">
                <input type="hidden" name="mode" value="{{ ApkukGenerationRun::MODE_PREVIEW }}">
                <x-admin.button variant="secondary">Jalankan pratinjau (tanpa WO)</x-admin.button>
            </form>
            @if ($blockedReason)
                <x-admin.unavailable-action label="Generate WO" variant="secondary" :reason="$blockedReason" />
            @else
                <form method="POST" action="{{ route('admin.shift-quota.run') }}" data-confirm="Buat WO APKUK otomatis untuk shift ini di lingkungan {{ $environment }}?">
                    @csrf
                    <input type="hidden" name="operational_date" value="{{ $date }}">
                    <input type="hidden" name="shift_code" value="{{ $code }}">
                    <input type="hidden" name="mode" value="{{ ApkukGenerationRun::MODE_GENERATE }}">
                    <x-admin.button>Generate WO ({{ $environment }})</x-admin.button>
                </form>
            @endif
        </div>
    </x-admin.panel>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-admin.stat-card label="WO limpahan sah" :value="$totals['carry']" hint="Reschedule disetujui ke shift ini" />
        <x-admin.stat-card label="WO manual/lain" :value="$totals['manual']" hint="Terjadwal di shift ini, bukan dari generator" />
        <x-admin.stat-card label="WO dari generator" :value="$totals['generated']" hint="Tersimpan nyata (source SYSTEM)" tone="blue" />
        <x-admin.stat-card label="Slot kosong" :value="$totals['open']" :hint="'Total target '.($rows->count() * $target)" tone="amber" />
        <x-admin.stat-card label="Kelebihan beban" :value="$totals['overload']" hint="WO di atas kuota 6 karena limpahan sah (tidak dibatalkan)" />
    </div>

    <x-admin.panel flush title="WO nyata tersimpan per Posko" description="Data WO sungguhan di database lingkungan ini. WO dibatalkan tidak menempati slot. WO manual Admin ditolak bila shift sudah terisi 6; hanya limpahan sah yang boleh melebihi 6. Kolom alokasi menampilkan angka beku saat generate.">
        @if ($load->failed('scheduled') || $load->failed('poskos'))
            <x-admin.error-state />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th><th class="px-4 py-3 text-right">Target</th><th class="px-4 py-3 text-right">Limpahan</th><th class="px-4 py-3 text-right">Manual/lain</th><th class="px-4 py-3 text-right">Generator</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Slot kosong</th><th class="px-4 py-3 text-right">Kelebihan</th><th class="px-4 py-3">Alokasi generate</th>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr class="hover:bg-slate-50" data-posko="{{ $row['posko']->nama_posko }}">
                        <td class="px-4 py-3 text-xs font-semibold">{{ $row['posko']->nama_posko }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $target }}</td>
                        <td class="px-4 py-3 text-right text-xs" title="{{ $row['carry_over']->pluck('code')->implode(', ') }}">{{ $row['carry_over']->count() }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row['manual']->count() }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row['generated']->count() }}</td>
                        <td class="px-4 py-3 text-right text-xs font-semibold">{{ $row['total'] }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row['open_slots'] }}</td>
                        <td class="px-4 py-3 text-right text-xs">
                            @if ($row['overload'])
                                <span class="font-semibold">{{ $row['overload'] }}</span>
                                @if ($row['overload_from_carry_over'])
                                    <div class="text-slate-500" data-overload-source="carry-over">dari limpahan sah, dipertahankan</div>
                                @else
                                    <div class="text-red-700" data-overload-source="other">melebihi limpahan: periksa WO lain</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if ($row['allocation'])
                                Eksekusi #{{ $row['allocation']->generation_run_id }} · {{ $fmt($row['allocation']->generated_at) }}
                                <div class="text-slate-500" data-allocation-frozen>Saat generate: limpahan {{ $row['allocation']->carry_over_count }} + lain {{ $row['allocation']->existing_count }} + dibuat {{ $row['allocation']->generated_count }} = {{ $row['allocation']->total_count }}</div>
                                @if ($row['allocation']->shortfall_count > 0)<div class="text-amber-700">Kekurangan kandidat {{ $row['allocation']->shortfall_count }} (tidak diisi ulang otomatis)</div>@endif
                                @if ($row['allocation_drift'])
                                    <div class="text-blue-700" data-allocation-drift="{{ $row['allocation_drift'] }}">Berubah setelah generate: WO nyata kini {{ $row['total'] }} ({{ $row['allocation_drift'] > 0 ? '+' : '' }}{{ $row['allocation_drift'] }})</div>
                                @endif
                            @else
                                <span class="text-slate-400">Belum digenerate</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </x-admin.panel>

    <x-admin.panel :title="'Hasil pratinjau terakhir'.($latestPreview ? ' (#'.$latestPreview->id.')' : '')" :description="$latestPreview ? 'SIMULASI · '.$fmt($latestPreview->finished_at ?? $latestPreview->started_at).' · '.$latestPreview->strategy.' '.$latestPreview->strategy_version.' · tidak ada WO yang dibuat' : 'Belum ada pratinjau untuk shift ini.'">
        @if ($latestPreview && $latestPreview->status === ApkukGenerationRun::STATUS_COMPLETED)
            <div class="space-y-4">
                <x-admin.table min-width="min-w-[900px]">
                    <x-slot:head>
                        <th class="px-4 py-3">Posko</th><th class="px-4 py-3 text-right">Limpahan</th><th class="px-4 py-3 text-right">Lain</th><th class="px-4 py-3 text-right">Akan dibuat</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Kekurangan</th><th class="px-4 py-3 text-right">Kelebihan</th><th class="px-4 py-3">Kandidat terpilih (peringkat · tiang · kurang bh)</th>
                    </x-slot:head>
                    @foreach ($latestPreview->summary ?? [] as $item)
                        <tr data-preview-posko="{{ $item['posko'] }}">
                            <td class="px-4 py-3 text-xs font-semibold">{{ $item['posko'] }}<div class="font-normal text-slate-500">{{ $poskoStatus[$item['status']] ?? $item['status'] }}</div></td>
                            <td class="px-4 py-3 text-right text-xs">{{ $item['carry_over'] }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ $item['existing'] }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ $item['new_this_run'] }}</td>
                            <td class="px-4 py-3 text-right text-xs font-semibold">{{ $item['total'] }}</td>
                            <td class="px-4 py-3 text-right text-xs {{ $item['shortfall'] ? 'text-amber-700 font-semibold' : '' }}">{{ $item['shortfall'] }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ $item['overload'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @forelse ($item['planned'] as $planned)
                                    <div>#{{ $planned['rank'] }} · <a class="text-blue-700" href="{{ route('admin.apkuk.show', $planned['apkuk_id']) }}">{{ $planned['no_tiang'] }}</a> · {{ $planned['kurang_bh'] }} bh</div>
                                @empty
                                    @if ($item['status'] === ApkukShiftGenerator::POSKO_ALREADY_GENERATED)
                                        <span class="text-amber-700" data-frozen-allocation>Alokasi sudah dibekukan oleh eksekusi #{{ $item['generated_by_run_id'] }}: {{ $item['new'] }} WO dibuat{{ $item['shortfall'] ? ', kekurangan '.$item['shortfall'].' tidak diisi ulang otomatis' : '' }}. Kandidat sah saat ini: {{ $item['eligible_candidates'] }}.@isset($item['current_total']) WO nyata saat pratinjau: {{ $item['current_total'] }}.@endisset</span>
                                    @else
                                        <span class="text-slate-400">{{ $item['eligible_candidates'] === 0 ? 'Tidak ada kandidat sah untuk Posko ini' : 'Tidak ada slot kosong' }}</span>
                                    @endif
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        @elseif ($latestPreview)
            <x-admin.notice tone="warning" :title="$latestPreview->statusLabel().':'">{{ $latestPreview->message }}</x-admin.notice>
        @else
            <x-admin.empty-state title="Belum ada pratinjau." description="Jalankan pratinjau untuk melihat rencana tanpa membuat WO." />
        @endif
    </x-admin.panel>

    @php $heldRun = $latestPreview ?? $latestGenerate; $breakdown = $heldRun?->breakdown; @endphp
    <x-admin.panel :title="'Rincian laporan APKUK'.($breakdown ? ' (eksekusi #'.$heldRun->id.')' : '')" description="Mengapa laporan APKUK menjadi atau tidak menjadi kandidat WO pada eksekusi terakhir. Laporan tidak sah dihitung sekali menurut alasan utamanya.">
        @if (! $breakdown)
            <x-admin.empty-state title="Belum ada rincian." description="Jalankan pratinjau untuk menghitung rincian laporan." />
        @else
            <div class="grid gap-6 lg:grid-cols-3">
                <div data-breakdown="eligible-mapped">
                    <h3 class="text-xs font-semibold text-slate-800">Sah dan terpetakan ke Posko: {{ $breakdown['eligible_mapped'] }}</h3>
                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @foreach ($breakdown['eligible_mapped_by_posko'] as $posko => $count)
                            <li class="flex justify-between gap-3"><span>{{ $posko }}</span><span class="font-semibold">{{ $count }}</span></li>
                        @endforeach
                    </ul>
                </div>
                <div data-breakdown="eligible-held">
                    <h3 class="text-xs font-semibold text-slate-800">Sah, Section–Posko tidak terbukti (ditahan): {{ $breakdown['eligible_held'] }}</h3>
                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @forelse ($breakdown['eligible_held_by_reason'] as $reason => $count)
                            <li class="flex justify-between gap-3" data-breakdown-reason="{{ $reason }}"><span>{{ SectionPoskoMap::LABELS[$reason] ?? $reason }}</span><span class="font-semibold">{{ $count }}</span></li>
                        @empty
                            <li class="text-slate-400">Tidak ada.</li>
                        @endforelse
                    </ul>
                </div>
                <div data-breakdown="not-eligible">
                    <h3 class="text-xs font-semibold text-slate-800">Tidak sah: {{ $breakdown['not_eligible'] }}</h3>
                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @forelse ($breakdown['not_eligible_by_primary'] as $issue => $count)
                            <li class="flex justify-between gap-3" data-breakdown-reason="{{ $issue }}"><span>{{ ApkukCandidateService::LABELS[$issue] ?? $issue }}</span><span class="font-semibold">{{ $count }}</span></li>
                        @empty
                            <li class="text-slate-400">Tidak ada.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-500">
                Laporan aktif {{ $breakdown['reports_active'] }} = sah {{ $breakdown['eligible'] }} + tidak sah {{ $breakdown['not_eligible'] }}.
                Histori/diarsipkan (tidak dinilai): {{ $breakdown['reports_archived'] }}.
            </p>
        @endif
    </x-admin.panel>

    <x-admin.panel flush :title="'Kandidat ditahan ('.count($heldRun?->held ?? []).')'" description="Kandidat APKUK yang sah tetapi Posko penanggung jawabnya tidak dapat dibuktikan dari SECTION_PIC_RAW. Tidak diberikan ke Posko mana pun; perbaiki Section (koreksi internal ber-audit) atau data sumber.">
        @if (empty($heldRun?->held))
            <x-admin.empty-state title="Tidak ada kandidat ditahan pada eksekusi terakhir." />
        @else
            <x-admin.table>
                <x-slot:head><th class="px-4 py-3">Tiang</th><th class="px-4 py-3">Penyulang</th><th class="px-4 py-3">Section sumber / efektif</th><th class="px-4 py-3">Alasan</th></x-slot:head>
                @foreach (array_slice($heldRun->held, 0, 100) as $held)
                    <tr data-held-reason="{{ $held['reason'] }}">
                        <td class="px-4 py-3 text-xs"><a class="font-semibold text-blue-700" href="{{ route('admin.apkuk.show', $held['apkuk_id']) }}">{{ $held['no_tiang'] }}</a><div class="font-mono text-[10px] text-slate-400">{{ $held['source_id'] }}</div></td>
                        <td class="px-4 py-3 text-xs">{{ $held['penyulang'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $held['section_sumber'] ?? '(kosong)' }}@if ($held['section_efektif'] && $held['section_efektif'] !== $held['section_sumber'])<div class="text-blue-700">Efektif: {{ $held['section_efektif'] }}</div>@endif</td>
                        <td class="px-4 py-3 text-xs">{{ $held['reason_label'] }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
            @if (count($heldRun->held) > 100)
                <p class="px-4 py-3 text-xs text-slate-500">Menampilkan 100 dari {{ count($heldRun->held) }} kandidat ditahan.</p>
            @endif
        @endif
    </x-admin.panel>

    <x-admin.panel flush title="Riwayat eksekusi shift ini" description="Pratinjau (simulasi) dan generate tercatat terpisah, lengkap dengan strategi, lingkungan, dan pelaksana.">
        <x-admin.table>
            <x-slot:head><th class="px-4 py-3">#</th><th class="px-4 py-3">Mode</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Strategi</th><th class="px-4 py-3">Lingkungan</th><th class="px-4 py-3">Oleh</th><th class="px-4 py-3">Waktu</th></x-slot:head>
            @forelse ($runs as $run)
                <tr>
                    <td class="px-4 py-3 text-xs">{{ $run->id }}</td>
                    <td class="px-4 py-3 text-xs">{{ $run->modeLabel() }}</td>
                    <td class="px-4 py-3 text-xs"><x-admin.status-badge :tone="$statusTone[$run->status] ?? 'slate'">{{ $run->statusLabel() }}</x-admin.status-badge>@if ($run->message)<div class="mt-1 text-slate-500">{{ $run->message }}</div>@endif</td>
                    <td class="px-4 py-3 text-xs">{{ $run->strategy }} {{ $run->strategy_version }}<div class="text-slate-500">{{ $run->is_machine_learning ? 'Model ML' : 'Rule-based (bukan ML)' }}</div></td>
                    <td class="px-4 py-3 text-xs">{{ $run->app_environment }}</td>
                    <td class="px-4 py-3 text-xs">{{ $run->triggeredBy?->name ?? $run->triggeredBy?->username ?? $run->trigger }}</td>
                    <td class="px-4 py-3 text-xs">{{ $fmt($run->started_at) }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-admin.empty-state title="Belum ada eksekusi untuk shift ini." /></td></tr>
            @endforelse
        </x-admin.table>
    </x-admin.panel>
</div>
@endsection
