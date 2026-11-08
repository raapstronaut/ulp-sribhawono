@extends('layouts.admin')

@section('title', 'APKUK '.$apkuk->no_tiang)

@section('content')
@php
    use App\Models\ApkukSectionOverride;
    use App\Services\Apkuk\ApkukSectionService;
    use App\Support\PenyulangName;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $stateInfo = [
        'aktif' => ['Aktif di sumber', 'emerald', 'Ada di snapshot APKUK_RAW terakhir.'],
        'menunggu' => ['Menunggu rekonsiliasi', 'amber', 'Tidak ada di snapshot APKUK_RAW terakhir; tidak dihitung aktif dan tidak dapat menjadi target WO baru sampai muncul kembali atau diputuskan Admin.'],
        'histori' => ['Histori (diarsipkan)', 'slate', 'Diarsipkan atas konfirmasi pemilik data; baris tidak dihapus.'],
    ][$state];
    // Kolom APKUK_RAW A–L sesuai urutan header sumber (ApkukSourceReader::EXPECTED_HEADERS).
    $sourceColumns = [
        ['A', 'ID', $apkuk->source_id],
        ['B', 'Tanggal', $apkuk->tanggal?->format('d/m/Y')],
        ['C', 'Petugas', $apkuk->petugas],
        ['D', 'Penyulang', $apkuk->penyulang],
        ['E', 'Section', $apkuk->section],
        ['F', 'No. Tiang/Gardu', $apkuk->no_tiang],
        ['G', 'Naik Dari', $apkuk->naik_dari],
        ['H', 'Terpasang bh', $apkuk->terpasang_bh],
        ['I', 'Kurang bh', $apkuk->kurang_bh],
        ['J', 'Status', $apkuk->status],
        ['K', 'Koordinat', $apkuk->koordinat],
        ['L', 'Foto', $apkuk->foto],
    ];
    $isBlank = fn ($value) => $value === null || (is_string($value) && trim($value) === '');
    $originLabels = [
        ApkukSectionService::ORIGIN_SOURCE => 'Nilai sumber',
        ApkukSectionService::ORIGIN_OVERRIDE => 'Koreksi Admin (sumber kosong/DUMMY)',
        ApkukSectionService::ORIGIN_NONE => 'Tidak diketahui (sumber kosong/DUMMY, belum dikoreksi)',
    ];
    $normalizedRaw = PenyulangName::normalize($apkuk->penyulang);
    $sourcePlaceholder = ApkukSectionService::isPlaceholder($apkuk->section);
    $overrideActive = $override?->isActive() ?? false;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" parent="APKUK" :parent-url="route('admin.apkuk.index', ['tab' => $state])" :title="'Laporan '.$apkuk->no_tiang"
        :description="'ID laporan '.$apkuk->source_id.' · '.$stateInfo[2]" state="live">
        <x-slot:actions>
            <x-admin.status-badge :tone="$stateInfo[1]">{{ $stateInfo[0] }}</x-admin.status-badge>
            @if ($state === 'menunggu')
                <x-admin.button variant="secondary" size="sm" :href="route('admin.apkuk-reconciliation.index')">Buka antrean rekonsiliasi</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- APKUK-DETAIL-REDESIGN-V2 --}}
    <section class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm sm:p-5" aria-label="Ringkasan laporan APKUK">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold text-white">DETAIL LAPORAN APKUK</span>
                <h2 class="mt-3 text-lg font-bold text-slate-900">{{ $apkuk->no_tiang }}</h2>
                <p class="mt-1 text-xs text-slate-600">Penyulang {{ $apkuk->penyulang ?? '-' }} &bull; {{ $apkuk->tanggal?->format('d/m/Y') ?? 'Tanggal tidak tersedia' }} &bull; ID sumber {{ $apkuk->source_id }}</p>
            </div>
            <x-admin.status-badge :tone="$stateInfo[1]">{{ $stateInfo[0] }}</x-admin.status-badge>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Terpasang menurut SPS BOS</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $apkuk->terpasang_bh ?? '-' }} <span class="text-xs font-medium text-slate-500">BH</span></p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Kurang menurut SPS BOS</p>
                <p class="mt-1 text-2xl font-black text-amber-700">{{ $apkuk->kurang_bh ?? '-' }} <span class="text-xs font-medium text-slate-500">BH</span></p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Status laporan sumber</p>
                <p class="mt-1 text-sm font-bold text-slate-800">{{ $apkuk->status ?? '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">Bukan status Work Order</p>
            </div>
        </div>
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="Navigasi detail APKUK">
            <a href="#apkuk-nilai-sistem" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">Kelayakan &amp; Section</a>
            <a href="#apkuk-data-sumber" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">12 kolom data sumber</a>
            <a href="#apkuk-koreksi-section" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">Koreksi Section</a>
            <a href="#apkuk-wo-terkait" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">WO terkait</a>
        </nav>
        <p class="mt-3 text-xs text-slate-600">Status sumber, evaluasi kelayakan, dan pelaksanaan WO adalah informasi yang berbeda. Nilai asli tidak diubah oleh koreksi internal.</p>
    </section>

    <x-admin.panel id="apkuk-data-sumber" title="Data sumber APKUK_RAW (kolom A–L)" description="Nilai tersimpan dari sinkronisasi SPS BOS, apa adanya. Kosong berarti sel sumber kosong; aplikasi tidak mengisi atau mengganti nilai sumber.">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($sourceColumns as [$column, $header, $value])
                <div data-source-column="{{ $column }}">
                    <dt class="text-xs text-slate-500"><span class="font-mono text-[10px] text-slate-400">{{ $column }}</span> · {{ $header }}</dt>
                    <dd class="mt-0.5 break-words font-semibold text-slate-900">
                        @if ($isBlank($value))
                            <span class="font-normal italic text-slate-400">Kosong di sumber</span>
                        @elseif ($column === 'L' && preg_match('#^https?://#i', (string) $value))
                            <a href="{{ $value }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800">Buka foto</a>
                            <span class="block break-all text-[11px] font-normal text-slate-500">{{ $value }}</span>
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-4 border-t border-slate-100 pt-3 text-[11px] text-slate-500">
            Baris sumber terakhir: {{ $apkuk->source_row_number ?? '-' }} · Sumber input: {{ $apkuk->sumber_input ?? '-' }} · Sinkron terakhir: {{ $fmt($apkuk->last_synced_at) }}
            @if ($apkuk->source_missing_at) · Diarsipkan: {{ $fmt($apkuk->source_missing_at) }} @endif
        </p>
    </x-admin.panel>

    <x-admin.panel id="apkuk-nilai-sistem" title="Nilai turunan sistem" description="Dihitung aplikasi dari nilai sumber untuk pencocokan dan WO; tidak mengubah nilai sumber.">
        <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.detail-item label="Penyulang master">
                @if ($load->failed('penyulang'))
                    Gagal dimuat
                @elseif ($masterPenyulang)
                    {{ $masterPenyulang->nama_penyulang }}
                    @if ($normalizedRaw !== $canonicalPenyulang || trim((string) $apkuk->penyulang) !== $normalizedRaw)
                        <span class="block text-[11px] font-normal text-slate-500">Dinormalisasi dari "{{ $apkuk->penyulang }}"</span>
                    @endif
                @else
                    <span class="font-normal text-amber-700">Tidak cocok dengan master</span>
                @endif
            </x-admin.detail-item>
            <x-admin.detail-item label="Koordinat terbaca">
                @if ($apkuk->latitude !== null && $apkuk->longitude !== null)
                    <a class="text-blue-600 hover:text-blue-800" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $apkuk->latitude }},{{ $apkuk->longitude }}">{{ $apkuk->latitude }}, {{ $apkuk->longitude }}</a>
                @elseif ($isBlank($apkuk->koordinat))
                    <span class="font-normal text-slate-500">Tidak ada (kolom K kosong)</span>
                @else
                    <span class="font-normal text-amber-700">Kolom K tidak dapat dibaca sebagai koordinat</span>
                @endif
            </x-admin.detail-item>
            <x-admin.detail-item label="Section efektif">
                {{ $section['value'] ?? '—' }}
                <span class="block text-[11px] font-normal text-slate-500">{{ $originLabels[$section['origin']] }}</span>
                @if ($section['source_conflict'])
                    <span class="block text-[11px] font-normal text-amber-700">Sumber kini terisi dan berbeda dari koreksi "{{ $override->section_name }}"; nilai sumber yang berlaku.</span>
                @endif
            </x-admin.detail-item>
            <x-admin.detail-item label="Kelayakan WO">
                @if ($state === 'histori')
                    <span class="font-normal text-slate-500">Laporan histori tidak dinilai</span>
                @elseif ($load->failed('candidate'))
                    Gagal dimuat
                @elseif ($candidate?->isEligible())
                    <a href="{{ route('admin.work-orders.create', ['apkuk_id' => $apkuk->id]) }}" class="text-emerald-700 hover:text-emerald-900">Layak — buat WO</a>
                @elseif ($candidate)
                    <ul class="list-disc pl-4 text-[11px] font-normal text-slate-600">
                        @foreach ($candidate->issueLabels() as $label)<li>{{ $label }}</li>@endforeach
                    </ul>
                @else
                    -
                @endif
                @if ($candidate && $candidate->warnings !== [])
                    <span class="block text-[11px] font-normal text-amber-700">Catatan: {{ implode('; ', $candidate->warningLabels()) }}</span>
                @endif
            </x-admin.detail-item>
        </dl>
    </x-admin.panel>

    <x-admin.panel id="apkuk-koreksi-section" title="Koreksi Section internal" description="Untuk laporan yang Section sumbernya kosong/DUMMY. Nilai sumber tetap tersimpan; koreksi tercatat di audit dan tidak mengubah WO yang sudah dibuat.">
        <div class="space-y-4 text-sm">
            @if ($override)
                <dl class="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-admin.detail-item label="Status koreksi">
                        <x-admin.status-badge :tone="$overrideActive ? 'blue' : 'slate'">{{ $overrideActive ? 'Berlaku' : 'Dicabut' }}</x-admin.status-badge>
                    </x-admin.detail-item>
                    <x-admin.detail-item label="Section koreksi">{{ $override->section_name ?? '—' }}</x-admin.detail-item>
                    <x-admin.detail-item label="Dasar referensi">{{ $override->basisLabel() ?? '—' }}</x-admin.detail-item>
                    <x-admin.detail-item label="Terakhir diubah">{{ $fmt($override->updated_at) }} · {{ $override->updatedBy?->name ?? $override->updatedBy?->username ?? '-' }}</x-admin.detail-item>
                </dl>
            @endif

            @if (! $sourcePlaceholder)
                <x-admin.notice title="Section sumber terisi.">
                    Koreksi internal hanya untuk Section kosong/DUMMY. Perbaikan nilai yang sudah terisi dilakukan pemilik data di SPS BOS.
                </x-admin.notice>
            @elseif ($load->failed('references'))
                <x-admin.error-state :inset="false" />
            @elseif ($sectionReferences === [])
                <x-admin.notice tone="warning" title="Belum ada referensi Section.">
                    Tidak ada pemutus SECTION_PIC_RAW maupun Section laporan lain pada penyulang ini yang dapat dipakai. Section tidak diisi dengan tebakan.
                </x-admin.notice>
            @else
                <form method="POST" action="{{ route('admin.apkuk.section.update', $apkuk) }}" class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    @csrf
                    <input type="hidden" name="action" value="set">
                    <x-admin.select name="section_name" label="Section koreksi" required placeholder="Pilih Section"
                        :options="collect($sectionReferences)->mapWithKeys(fn ($ref) => [$ref['value'] => $ref['value'].' — '.ApkukSectionOverride::BASIS_LABELS[$ref['basis']]])->all()"
                        hint="Hanya Section yang ada di referensi tersedia; master Section canonical belum ada." />
                    <div class="lg:col-span-2">
                        <x-admin.textarea name="reason" label="Alasan / sumber informasi" required rows="2" hint="Minimal 10 karakter, mis. konfirmasi petugas posko atau peta jaringan." />
                    </div>
                    <div class="lg:col-span-3"><x-admin.button>{{ $overrideActive ? 'Ganti koreksi Section' : 'Simpan koreksi Section' }}</x-admin.button></div>
                </form>
            @endif

            @if ($overrideActive)
                <form method="POST" action="{{ route('admin.apkuk.section.update', $apkuk) }}" class="flex flex-col gap-3 border-t border-slate-100 pt-3 md:flex-row" data-confirm="Cabut koreksi Section? Section efektif kembali ke nilai sumber.">
                    @csrf
                    <input type="hidden" name="action" value="revoke">
                    <input type="text" name="reason" required minlength="10" maxlength="2000" placeholder="Alasan pencabutan (wajib)" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <x-admin.button variant="danger">Cabut koreksi</x-admin.button>
                </form>
            @endif

            <div>
                <h3 class="mb-2 text-xs font-bold text-slate-700">Riwayat koreksi</h3>
                @if ($load->failed('section_history'))
                    <x-admin.error-state :inset="false" />
                @elseif ($sectionHistory->isEmpty())
                    <p class="text-xs text-slate-500">Belum ada koreksi Section untuk laporan ini.</p>
                @else
                    <ul class="space-y-2 text-xs">
                        @foreach ($sectionHistory as $log)
                            <li class="rounded-lg border border-slate-200 p-2">
                                <strong>{{ $log->actionLabel() }}</strong> · {{ $fmt($log->created_at) }} · {{ $log->actor?->name ?? $log->actor?->username ?? '-' }}
                                <div class="text-slate-600">Section koreksi: {{ $log->before['section_koreksi'] ?? '—' }} → {{ $log->after['section_koreksi'] ?? '—' }} · Section sumber saat itu: {{ $log->after['section_sumber'] ?? '(kosong)' }}</div>
                                <div class="text-slate-500">Alasan: {{ $log->reason }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-admin.panel title="Rekonsiliasi sumber" description="Riwayat antrean ketika laporan tidak ditemukan di snapshot APKUK_RAW.">
            @if ($apkuk->reconciliationItems->isEmpty())
                <p class="text-xs text-slate-500">Laporan ini belum pernah masuk antrean rekonsiliasi.</p>
            @else
                <ul class="space-y-2 text-xs">
                    @foreach ($apkuk->reconciliationItems as $item)
                        <li class="rounded-lg border border-slate-200 p-2">
                            <div class="flex justify-between gap-2"><strong>{{ $item->kindLabel() }}</strong>
                                <x-admin.status-badge :tone="$item->isOpen() ? 'amber' : 'slate'">{{ $item->isOpen() ? 'Terbuka' : 'Selesai' }}</x-admin.status-badge></div>
                            <div class="text-slate-600">Terdeteksi {{ $fmt($item->first_detected_at) }} – {{ $fmt($item->last_detected_at) }} ({{ $item->detection_count }}×)</div>
                            @if (! $item->isOpen())
                                <div class="text-slate-600">{{ $item->resolutionLabel() }} · {{ $fmt($item->resolved_at) }} · {{ $item->resolvedBy?->name ?? $item->resolvedBy?->username ?? 'Sistem' }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.panel>

        <x-admin.panel id="apkuk-wo-terkait" title="Work Order terkait" description="WO APKUK yang menargetkan laporan ini (snapshot target saat WO dibuat).">
            @if ($load->failed('work_orders'))
                <x-admin.error-state :inset="false" />
            @elseif ($workOrderTargets->isEmpty())
                <p class="text-xs text-slate-500">Belum ada WO untuk laporan ini.</p>
            @else
                <ul class="space-y-2 text-xs">
                    @foreach ($workOrderTargets as $target)
                        @php $wo = $target->workOrder; @endphp
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-2">
                            <span><a class="font-semibold text-blue-700" href="{{ route('admin.work-orders.show', $wo) }}">{{ $wo->code }}</a>
                                · {{ $wo->posko?->nama_posko ?? '-' }} · {{ $wo->work_date?->format('d/m/Y') }} Shift {{ $wo->shift_code ?? '-' }}
                                · target {{ $target->target_quantity }} bh · terpasang {{ $target->installed_quantity ?? '—' }}</span>
                            <x-admin.wo-status :work-order="$wo" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.panel>
    </div>

    <x-admin.panel title="Laporan lain di tiang yang sama" description="No. tiang dan penyulang yang sama; laporan terbaru per tiang yang dinilai untuk WO." flush>
        @if ($load->failed('same_pole'))
            <x-admin.error-state />
        @else
            <x-admin.table>
                <x-slot:head><th class="px-4 py-3">ID laporan</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Status sumber</th><th class="px-4 py-3 text-right">Terpasang / Kurang</th><th class="px-4 py-3">Status data</th></x-slot:head>
                @forelse ($samePole as $other)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-[11px]"><a class="text-blue-700" href="{{ route('admin.apkuk.show', $other->id) }}">{{ $other->source_id }}</a></td>
                        <td class="px-4 py-3 text-xs">{{ $other->tanggal ? \Illuminate\Support\Carbon::parse($other->tanggal)->format('d/m/Y') : '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $other->status ?? '-' }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ $other->terpasang_bh ?? '-' }} / {{ $other->kurang_bh ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ ['aktif' => 'Aktif', 'menunggu' => 'Menunggu rekonsiliasi', 'histori' => 'Histori'][$other->state] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-admin.empty-state title="Tidak ada laporan lain untuk tiang ini." /></td></tr>
                @endforelse
            </x-admin.table>
        @endif
    </x-admin.panel>
</div>
@endsection
