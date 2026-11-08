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
    <x-admin.posko-tabs />
    <!-- KUOTA_SHIFT_REDESIGN_V2: presentation-only; no workflow changes -->
<x-admin.page-header section="Pekerjaan" title="Kuota Shift APKUK" state="partial"
    description="Pantau target dan realisasi pemasangan APKUK per POSKO, untuk tanggal dan shift yang dipilih.">
    <x-slot:actions>
        <x-admin.status-badge tone="slate">Lingkungan: {{ $environment }}</x-admin.status-badge>
    </x-slot:actions>
</x-admin.page-header>

<section class="rounded-xl border border-blue-200 bg-blue-50 p-4 shadow-sm" aria-label="Ringkasan shift APKUK">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Monitor kuota</span>
                <span class="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">{{ $isCurrent ? 'Shift berjalan' : 'Shift terpilih' }}</span>
            </div>
            <h2 class="mt-3 text-lg font-bold text-slate-900">Shift {{ $code }} - {{ $shift['name'] ?? 'APKUK' }} / {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</h2>
            <p class="mt-1 text-xs text-slate-600">{{ $fmt($window['start']) }} - {{ $fmt($window['end']) }}. Angka di bawah adalah WO nyata pada periode ini, bukan jumlah unit APKUK.</p>
        </div>
        <div class="rounded-lg border border-blue-100 bg-white px-4 py-3 text-sm shadow-sm">
            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Target per POSKO</div>
            <div class="mt-1 text-2xl font-bold text-blue-700">{{ $target }} <span class="text-xs font-medium text-slate-500">WO / shift</span></div>
            <div class="mt-1 text-[11px] text-slate-500">{{ $rows->count() }} POSKO aktif, total {{ $rows->count() * $target }} slot</div>
        </div>
    </div>
    <div class="mt-3 rounded-lg border border-blue-100 bg-white/80 px-3 py-2 text-xs text-slate-600">
        <strong class="text-slate-800">Status generator:</strong>
        {{ $blockedReason ? 'Generate WO nonaktif' : 'Generate WO diizinkan oleh pengaman lingkungan' }}.
        Pratinjau hanya simulasi dan tidak membuat WO. Kelebihan beban tercatat: <strong>{{ $totals['overload'] }} WO</strong>.
    </div>
</section>

    <details class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm" data-quota-limitations>
    <summary class="flex cursor-pointer items-center justify-between gap-3 text-xs font-semibold text-amber-900">
        <span>Kesiapan generator dan batasan data APKUK</span>
        <span class="text-amber-700">Lihat aturan +</span>
    </summary>
    <div class="mt-3 space-y-2 border-t border-amber-200 pt-3 text-xs leading-relaxed text-amber-900">
        <p>Generator otomatis {{ $blockedReason ? 'nonaktif' : 'diizinkan di lingkungan ini' }}. Strategi yang tersedia adalah {{ $ranking->name() }} {{ $ranking->version() }}: baseline rule-based, bukan machine learning.</p>
        <p>Generate hanya dapat berjalan di lingkungan development/testing dengan aktivasi eksplisit. Production dan Neon tetap ditolak oleh pengaman sampai model ML tervalidasi dan prosedur kesiapan tersedia.</p>
        <p>Pratinjau hanya simulasi dan tidak membuat WO. Kandidat tanpa pemetaan Section-POSKO yang terbukti ditahan, bukan ditebak.</p>
    </div>
</details>

    <x-admin.panel flush title="Pilih periode dan jalankan simulasi" description="Ubah tanggal dan shift untuk membaca kuota. Pratinjau memeriksa kandidat tanpa membuat pekerjaan.">
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
                <x-admin.button variant="secondary">Hitung pratinjau (tanpa WO)</x-admin.button>
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

    <div class="space-y-3">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Ringkasan kuota shift</h2>
            <p class="mt-1 text-xs text-slate-500">Kuota {{ $target }} WO per POSKO; angka nyata terpisah dari hasil pratinjau.</p>
        </div>
        <span class="text-xs font-medium text-slate-500">Kelebihan beban: {{ $totals['overload'] }} WO</span>
    </div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-admin.stat-card label="Total target" :value="$rows->count() * $target" hint="Semua POSKO aktif pada shift ini" />
        <x-admin.stat-card label="WO terjadwal" :value="$rows->sum('total')" hint="WO nyata, tidak termasuk simulasi" tone="blue" />
        <x-admin.stat-card label="Slot kosong" :value="$totals['open']" hint="Kekurangan dari target per POSKO" tone="amber" />
        <x-admin.stat-card label="WO limpahan sah" :value="$totals['carry']" hint="Reschedule yang disetujui" />
        <x-admin.stat-card label="WO manual/lain" :value="$totals['manual']" hint="Bukan dari generator" />
        <x-admin.stat-card label="WO dari generator" :value="$totals['generated']" hint="Sudah tersimpan, bukan pratinjau" tone="blue" />
    </div>
</div>

    <x-admin.panel flush title="Pencapaian kuota per POSKO" description="WO nyata yang tersimpan, bukan simulasi. WO dibatalkan tidak mengisi slot. Hanya limpahan sah yang dapat membuat total melebihi target; alokasi generate adalah catatan saat proses dijalankan.">
        @if ($load->failed('scheduled') || $load->failed('poskos'))
            <x-admin.error-state />
        @else
            <x-admin.table>
                <x-slot:head>
                    <th class="px-4 py-3">Posko</th><th class="px-4 py-3 text-right">Target</th><th class="px-4 py-3 text-right">Limpahan</th><th class="px-4 py-3 text-right">Manual/lain</th><th class="px-4 py-3 text-right">Generator</th><th class="px-4 py-3">Terisi / target</th><th class="px-4 py-3 text-right">Slot kosong</th><th class="px-4 py-3 text-right">Kelebihan</th><th class="px-4 py-3">Alokasi generate</th>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr class="hover:bg-slate-50" data-posko="{{ $row['posko']->nama_posko }}">
                        <td class="px-4 py-3 text-xs font-semibold">{{ $row['posko']->nama_posko }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $target }}</td>
                        <td class="px-4 py-3 text-right text-xs" title="{{ $row['carry_over']->pluck('code')->implode(', ') }}">{{ $row['carry_over']->count() }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row['manual']->count() }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $row['generated']->count() }}</td>
                        <td class="px-4 py-3 text-xs">
    <div class="flex min-w-[135px] items-center gap-2">
        <span class="w-12 shrink-0 font-bold text-slate-900">{{ $row['total'] }}/{{ $target }}</span>
        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Kuota {{ $row['posko']->nama_posko }}" aria-valuemin="0" aria-valuenow="{{ min($row['total'], $target) }}" aria-valuemax="{{ $target }}">
            <div class="h-full rounded-full {{ $row['overload'] ? 'bg-amber-500' : 'bg-blue-600' }}" style="width: {{ $target > 0 ? min(100, round(100 * $row['total'] / $target)) : 0 }}%"></div>
        </div>
    </div>
</td>
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

    <details class="rounded-xl border border-slate-200 bg-white shadow-sm" data-quota-preview @if($latestPreview) open @endif>
    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
        <span>Hasil pratinjau {{ $latestPreview ? 'terakhir (#'.$latestPreview->id.')' : '- belum dijalankan' }}</span>
        <span class="text-xs text-blue-600">Lihat rincian +</span>
    </summary>
    <div class="px-3 pb-3">
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

        </div>
</details>

@php $heldRun = $latestPreview ?? $latestGenerate; $breakdown = $heldRun?->breakdown; @endphp
    <details class="rounded-xl border border-slate-200 bg-white shadow-sm" data-quota-breakdown @if($breakdown) open @endif>
    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
        <span>Kualitas kandidat dan rincian laporan</span>
        <span class="text-xs text-blue-600">Lihat rincian +</span>
    </summary>
    <div class="px-3 pb-3">
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

        </div>
</details>

<details class="rounded-xl border border-slate-200 bg-white shadow-sm" data-quota-held @if(count($heldRun?->held ?? []) > 0) open @endif>
    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
        <span>Kandidat ditahan ({{ count($heldRun?->held ?? []) }})</span>
        <span class="text-xs text-blue-600">Lihat rincian +</span>
    </summary>
    <div class="px-3 pb-3">
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

        </div>
</details>

<details class="rounded-xl border border-slate-200 bg-white shadow-sm" data-quota-history @if($runs->isNotEmpty()) open @endif>
    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
        <span>Riwayat pratinjau dan generate ({{ $runs->count() }})</span>
        <span class="text-xs text-blue-600">Lihat riwayat +</span>
    </summary>
    <div class="px-3 pb-3">
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
</details>
</div>
@endsection
