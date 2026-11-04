@extends('layouts.admin')

@section('title', $wo->code)

@section('content')
@php
    use App\Enums\WorkOrderStatus;
    use App\Models\WorkOrder;
    use App\Models\WorkOrderStatusLog;
    use App\Services\Work\WorkOrderEditor;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $target = $wo->apkukTarget;
    $location = $wo->locationTarget;
    $coordinates = $wo->targetCoordinates();
    $workable = in_array($wo->status, [WorkOrderStatus::Ditugaskan, WorkOrderStatus::DijadwalkanUlang, WorkOrderStatus::Dikerjakan], true);
    $startable = in_array($wo->status, [WorkOrderStatus::Ditugaskan, WorkOrderStatus::DijadwalkanUlang], true);
    $back = match ($wo->team_type) {
        WorkOrder::TEAM_HARSUS => ['admin.harsus.index', 'HARSUS'],
        WorkOrder::TEAM_ROW => ['admin.row.index', 'ROW'],
        default => ['admin.work-orders.index', 'Work Order'],
    };
    $reportTone = ['TIDAK_PERLU' => 'emerald', 'MENUNGGU_VERIFIKASI' => 'amber', 'DISETUJUI' => 'emerald', 'DITOLAK' => 'red'];
    $decisionTone = ['MENUNGGU_ACC' => 'amber', 'DISETUJUI' => 'emerald', 'DITOLAK' => 'red', 'TERBUKA' => 'amber', 'SELESAI' => 'emerald'];
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    @if ($wo->team_type === \App\Models\WorkOrder::TEAM_POSKO && $wo->work_type === \App\Models\WorkOrder::TYPE_PEMASANGAN_APKUK)
        <x-admin.posko-tabs />
    @endif
    <x-admin.page-header section="Pekerjaan" :parent="$back[1]" :parent-url="route($back[0])" :title="$wo->code"
        :description="$wo->teamLabel().' · '.$wo->workTypeLabel().' · '.$wo->sourceLabel()">
        <x-slot:actions>
            <x-admin.wo-status :work-order="$wo" />
            @if (WorkOrderEditor::isEditable($wo))
                <x-admin.button variant="secondary" size="sm" :href="route('admin.work-orders.edit', $wo)">Ubah data / jadwal</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($wo->status === WorkOrderStatus::MenungguVerifikasi || $wo->status === WorkOrderStatus::RescheduleDiajukan || $wo->escalations->contains('status', 'MENUNGGU_ACC'))
        <x-admin.notice tone="warning" title="Menunggu keputusan Admin:">
            WO ini memiliki laporan, pengajuan reschedule, atau limpahan yang menunggu keputusan.
            <a class="font-semibold underline" href="{{ route('admin.reviews.index') }}">Buka Review & Validasi</a>.
        </x-admin.notice>
    @endif

    @if ($wo->isApkuk())
        <section aria-label="Ringkasan WO APKUK" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div><div class="text-[11px] font-bold uppercase text-slate-500">Status pekerjaan</div><div class="mt-1 text-sm font-bold text-slate-900">{{ $wo->status->label() }}@if ($wo->isOverdue()) <span class="text-rose-700">· Terlambat</span>@endif</div></div>
                <div><div class="text-[11px] font-bold uppercase text-slate-500">POSKO penanggung jawab</div><div class="mt-1 text-sm font-bold text-slate-900">{{ $wo->posko?->nama_posko ?? 'Belum ditentukan' }}</div></div>
                <div><div class="text-[11px] font-bold uppercase text-slate-500">Jadwal operasional</div><div class="mt-1 text-sm font-bold text-slate-900">{{ $wo->work_date?->format('d/m/Y') ?? '—' }} · Shift {{ $wo->shift_code ?? '—' }}</div></div>
                <div><div class="text-[11px] font-bold uppercase text-slate-500">Target APKUK</div><div class="mt-1 text-sm font-bold text-slate-900">{{ $target?->target_quantity ?? '—' }} bh</div></div>
            </div>
        </section>
        <nav aria-label="Navigasi detail pekerjaan" class="flex flex-wrap gap-2 text-xs font-semibold">
            <a href="#penugasan" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-slate-700 hover:border-blue-300">Penugasan</a>
            <a href="#lokasi" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-slate-700 hover:border-blue-300">Lokasi & sumber</a>
            <a href="#laporan" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-slate-700 hover:border-blue-300">Laporan & bukti</a>
            @if ($wo->status->isActive())<a href="#aksi" class="rounded-full border border-blue-200 bg-blue-50 px-3 py-2 text-blue-700 hover:border-blue-400">Tindakan Admin</a>@endif
            <a href="#riwayat" class="rounded-full border border-slate-200 bg-white px-3 py-2 text-slate-700 hover:border-blue-300">Riwayat audit</a>
        </nav>
    @endif
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-admin.panel title="Penugasan" id="penugasan" class="lg:col-span-2">
            <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-3">
                <x-admin.detail-item label="Tim">{{ $wo->teamLabel() }}</x-admin.detail-item>
                <x-admin.detail-item label="Jenis pekerjaan">{{ $wo->workTypeLabel() }}</x-admin.detail-item>
                <x-admin.detail-item label="Tim pelaksana">{{ $wo->fieldTeam?->name ?? 'Semua tim '.$wo->teamLabel() }}</x-admin.detail-item>
                <x-admin.detail-item label="POSKO">{{ $wo->posko?->nama_posko }}</x-admin.detail-item>
                <x-admin.detail-item label="Penyulang">{{ $wo->penyulang?->nama_penyulang }}</x-admin.detail-item>
                <x-admin.detail-item label="Section">{{ $wo->section_name ?? 'Belum diketahui' }}</x-admin.detail-item>
                <x-admin.detail-item label="Tanggal operasional">{{ $wo->work_date?->format('d/m/Y') }}</x-admin.detail-item>
                <x-admin.detail-item label="Shift">{{ $shift ? 'Shift '.$shift['code'].' — '.$shift['name'].' ('.$shift['time'].')' : 'Tanpa shift' }}</x-admin.detail-item>
                <x-admin.detail-item label="Batas waktu">{{ $fmt($wo->due_at) }}</x-admin.detail-item>
                <x-admin.detail-item label="Mulai dikerjakan">{{ $fmt($wo->started_at) }}</x-admin.detail-item>
                <x-admin.detail-item label="Selesai">{{ $fmt($wo->completed_at) }}</x-admin.detail-item>
                <x-admin.detail-item label="Dibuat">{{ $fmt($wo->created_at) }} · {{ $wo->createdBy?->displayName() ?? 'Sistem' }}</x-admin.detail-item>
            </dl>
            @if ($wo->parent)
                <p class="mt-4 rounded-lg border border-violet-100 bg-violet-50 p-3 text-xs text-violet-800">Child WO limpahan dari <a class="font-semibold underline" href="{{ route('admin.work-orders.show', $wo->parent) }}">{{ $wo->parent->code }}</a>.</p>
            @endif
            @foreach ($wo->children as $child)
                <p class="mt-4 rounded-lg border border-violet-100 bg-violet-50 p-3 text-xs text-violet-800">Diteruskan ke <a class="font-semibold underline" href="{{ route('admin.work-orders.show', $child) }}">{{ $child->code }}</a> ({{ $child->teamLabel() }} · {{ $child->status->label() }}).</p>
            @endforeach
            @if ($wo->notes)
                <div class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700"><span class="mb-1 block text-xs text-slate-500">Catatan</span>{{ $wo->notes }}</div>
            @endif
            @if ($wo->cancelled_at)
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <span class="mb-1 block text-xs text-slate-500">Dibatalkan {{ $fmt($wo->cancelled_at) }} oleh {{ $wo->cancelledBy?->displayName() ?? '-' }}</span>
                    {{ $wo->cancel_reason }}
                </div>
            @endif
        </x-admin.panel>

        @if ($wo->isApkuk())
            <x-admin.panel title="Realisasi APKUK" description="Satuan buah (bh), terisi dari laporan pelaksanaan.">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Target</div><div class="text-2xl font-black text-slate-900">{{ $target?->target_quantity ?? '-' }}</div></div>
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Terpasang</div><div class="text-2xl font-black text-slate-900">{{ $target?->installed_quantity ?? '—' }}</div></div>
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Sisa</div><div class="text-2xl font-black text-slate-900">{{ $target?->remaining_quantity ?? '—' }}</div></div>
                </div>
            </x-admin.panel>
        @else
            <x-admin.panel title="Ringkasan">
                <dl class="space-y-3 text-sm">
                    <x-admin.detail-item label="Laporan">{{ $wo->reports->count() }}</x-admin.detail-item>
                    <x-admin.detail-item label="Foto bukti">{{ $wo->media->count() }}</x-admin.detail-item>
                    <x-admin.detail-item label="Kendala terbuka">{{ $wo->issues->where('status', 'TERBUKA')->count() }}</x-admin.detail-item>
                </dl>
            </x-admin.panel>
        @endif
    </div>

    @if ($target)
        <x-admin.panel id="lokasi" title="Target tiang (snapshot data APKUK)" :description="'Disalin dari baris apkuks #'.$target->apkuk_id.' saat WO dibuat; sinkronisasi berikutnya tidak mengubah target WO.'">
            <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
                <x-admin.detail-item label="No. tiang">{{ $target->no_tiang }}</x-admin.detail-item>
                <x-admin.detail-item label="Penyulang (sumber)">{{ $target->penyulang_source }}</x-admin.detail-item>
                <x-admin.detail-item label="Section (sumber)">{{ $target->section_source }}</x-admin.detail-item>
                <x-admin.detail-item label="Naik dari">{{ $target->naik_dari }}</x-admin.detail-item>
                <x-admin.detail-item label="Tanggal laporan">{{ $target->source_report_date?->format('d/m/Y') }}</x-admin.detail-item>
                <x-admin.detail-item label="Status sumber">{{ $target->source_status }}</x-admin.detail-item>
                <x-admin.detail-item label="Terpasang / kurang (sumber)">{{ $target->source_terpasang_bh ?? '-' }} / {{ $target->source_kurang_bh }}</x-admin.detail-item>
                <x-admin.detail-item label="Koordinat">
                    @if ($coordinates)<a class="text-blue-600 hover:text-blue-800" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $coordinates[0] }},{{ $coordinates[1] }}">{{ $coordinates[0] }}, {{ $coordinates[1] }}</a>@endif
                </x-admin.detail-item>
            </dl>
            <a href="{{ route('admin.apkuk.show', $target->apkuk_id) }}" class="mt-4 inline-flex text-xs font-bold text-blue-700 hover:underline">Lihat laporan APKUK sumber →</a>
        </x-admin.panel>
    @elseif ($location)
        <x-admin.panel title="Target lokasi" :description="$location->typeLabel().' · snapshot saat WO dibuat'">
            <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4">
                <x-admin.detail-item label="Lokasi / aset">{{ $location->label }}</x-admin.detail-item>
                <x-admin.detail-item label="Jenis">{{ $location->asset_type }}</x-admin.detail-item>
                <x-admin.detail-item label="Penyulang (sumber)">{{ $location->penyulang_source }}</x-admin.detail-item>
                <x-admin.detail-item label="Section (sumber)">{{ $location->section_source }}</x-admin.detail-item>
                <x-admin.detail-item label="Referensi sumber">{{ $location->sourceRefLabel() }}</x-admin.detail-item>
                <x-admin.detail-item label="Koordinat">
                    @if ($coordinates)<a class="text-blue-600 hover:text-blue-800" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $coordinates[0] }},{{ $coordinates[1] }}">{{ $coordinates[0] }}, {{ $coordinates[1] }}</a>@else Tidak tersedia @endif
                </x-admin.detail-item>
                @foreach (collect($location->snapshot ?? [])->only(['id_tiang', 'kondisi', 'jarak_pohon', 'tanggal_perkiraan_bahaya', 'note', 'finding_code']) as $key => $value)
                    @if (filled($value))
                        <x-admin.detail-item :label="ucfirst(str_replace('_', ' ', $key))">{{ $value }}</x-admin.detail-item>
                    @endif
                @endforeach
            </dl>
        </x-admin.panel>
    @endif

    <x-admin.panel title="Laporan pelaksanaan & bukti" id="laporan" :description="$wo->reports->isEmpty() ? 'Belum ada laporan. Laporan berisi foto BEFORE/AFTER, GPS, petugas, dan hasil pekerjaan.' : null">
        <div class="space-y-4">
            @foreach ($wo->reports as $report)
                <div class="rounded-lg border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <strong class="text-slate-900">{{ $report->typeLabel() }}</strong>
                        <x-admin.status-badge :tone="$reportTone[$report->verification_status] ?? 'slate'">{{ $report->verificationLabel() }}</x-admin.status-badge>
                    </div>
                    <div class="mt-1 text-xs text-slate-500">
                        {{ $fmt($report->occurred_at) }} · dikirim {{ $report->submittedByTeam?->name ?? 'Admin (atas nama tim)' }}
                        @if ($report->installed_quantity !== null) · terpasang {{ $report->installed_quantity }} bh · sisa {{ $report->remaining_quantity ?? '-' }} bh @endif
                        @if ($report->latitude) · GPS {{ $report->latitude }}, {{ $report->longitude }} @endif
                    </div>
                    <div class="mt-1 text-xs text-slate-600">Petugas: {{ $report->personnel->pluck('personnel_name')->implode(', ') ?: '-' }}</div>
                    @if ($report->notes)<p class="mt-2 text-xs text-slate-700">{{ $report->notes }}</p>@endif
                    @if ($report->media->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($report->media as $photo)
                                <a href="{{ route('admin.media.work-orders', $photo) }}" target="_blank" rel="noopener" class="group block w-24">
                                    <img src="{{ route('admin.media.work-orders', $photo) }}" alt="Foto {{ $photo->photo_type }}" loading="lazy" class="h-20 w-24 rounded-md border border-slate-200 object-cover group-hover:border-blue-400">
                                    <span class="mt-0.5 block text-center text-[10px] font-semibold text-slate-500">{{ $photo->photo_type }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($report->verified_at)
                        <p class="mt-2 text-xs text-slate-500">Diputuskan {{ $fmt($report->verified_at) }} oleh {{ $report->verifiedBy?->displayName() }}@if ($report->verification_note): “{{ $report->verification_note }}”@endif</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-admin.panel>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-admin.panel title="Reschedule" id="reschedule">
            @forelse ($wo->reschedules as $reschedule)
                <div class="mb-3 rounded-lg border border-slate-100 p-3 text-xs">
                    <div class="flex justify-between gap-2"><strong>{{ \App\Models\ReferenceCode::labelFor('RESCHEDULE_REASON', $reschedule->reason_code) ?? $reschedule->reason_code }}</strong><x-admin.status-badge :tone="$decisionTone[$reschedule->status] ?? 'slate'">{{ $reschedule->status }}</x-admin.status-badge></div>
                    <div class="mt-1 text-slate-500">{{ $reschedule->from_work_date?->format('d/m/Y') }} {{ $reschedule->from_shift_code }} → {{ $reschedule->proposed_work_date?->format('d/m/Y') }} {{ $reschedule->proposed_shift_code }}</div>
                    <div class="mt-1 text-slate-600">{{ $reschedule->note }}</div>
                    @if ($reschedule->reviewed_at)<div class="mt-1 text-slate-500">Keputusan {{ $reschedule->reviewedBy?->displayName() }}: {{ $reschedule->review_note ?? '-' }}</div>@endif
                </div>
            @empty
                <p class="text-xs text-slate-500">Belum ada pengajuan reschedule.</p>
            @endforelse
        </x-admin.panel>
        <x-admin.panel title="Limpahan">
            @forelse ($wo->escalations as $escalation)
                <div class="mb-3 rounded-lg border border-slate-100 p-3 text-xs">
                    <div class="flex justify-between gap-2"><strong>{{ $escalation->target_team_type }} · {{ \App\Models\WorkOrder::WORK_TYPE_LABELS[$escalation->target_work_type] ?? $escalation->target_work_type }}</strong><x-admin.status-badge :tone="$decisionTone[$escalation->status] ?? 'slate'">{{ $escalation->statusLabel() }}</x-admin.status-badge></div>
                    <div class="mt-1 text-slate-600">{{ $escalation->note }}</div>
                    @if ($escalation->childWorkOrder)<div class="mt-1">Child WO: <a class="font-semibold text-blue-600" href="{{ route('admin.work-orders.show', $escalation->childWorkOrder) }}">{{ $escalation->childWorkOrder->code }}</a></div>@endif
                    @if ($escalation->reviewed_at)<div class="mt-1 text-slate-500">Keputusan {{ $escalation->reviewedBy?->displayName() }}: {{ $escalation->review_note ?? '-' }}</div>@endif
                </div>
            @empty
                <p class="text-xs text-slate-500">Tidak ada permintaan limpahan.</p>
            @endforelse
        </x-admin.panel>
        <x-admin.panel title="Kendala / Special Case">
            @forelse ($wo->issues as $issue)
                <div class="mb-3 rounded-lg border border-slate-100 p-3 text-xs">
                    <div class="flex justify-between gap-2"><strong>{{ $issue->typeLabel() }}</strong><x-admin.status-badge :tone="$decisionTone[$issue->status] ?? 'slate'">{{ \App\Models\WorkOrderIssue::STATUS_LABELS[$issue->status] ?? $issue->status }}</x-admin.status-badge></div>
                    <div class="mt-1 text-slate-600">{{ $issue->description }}</div>
                    @if ($issue->resolved_at)<div class="mt-1 text-slate-500">{{ \App\Models\WorkOrderIssue::RESOLUTION_LABELS[$issue->resolution_action] ?? $issue->resolution_action }} — {{ $issue->resolution_note }} ({{ $issue->resolvedBy?->displayName() }})</div>@endif
                </div>
            @empty
                <p class="text-xs text-slate-500">Tidak ada kendala tercatat.</p>
            @endforelse
        </x-admin.panel>
    </div>

    @if ($wo->status->isActive())
        <x-admin.panel id="aksi" title="Catat atas nama tim lapangan" description="Dipakai selama aplikasi Android belum tersedia. Pencatatan memakai aturan yang sama dengan aplikasi (foto BEFORE/AFTER, petugas, status) dan tercatat sebagai input Admin di riwayat audit.">
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                    @if ($startable)
                        <form method="POST" action="{{ route('admin.work-orders.start', $wo) }}" class="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 sm:flex-row sm:items-end">
                            @csrf
                            <x-admin.select name="field_team_id" label="Tim pelaksana" :options="$teams->pluck('name', 'id')->all()" :value="$wo->field_team_id" placeholder="(sesuai WO)" class="sm:w-64" />
                            <x-admin.button>Tandai mulai dikerjakan</x-admin.button>
                        </form>
                    @endif

                    @if ($workable)
                        <form method="POST" action="{{ route('admin.work-orders.reports', $wo) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-slate-200 p-4" data-confirm="Simpan laporan pelaksanaan ini? Status WO akan berubah sesuai jenis laporan.">
                            @csrf
                            <h3 class="text-sm font-bold text-slate-900">Laporan pelaksanaan</h3>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <x-admin.select name="report_type" label="Jenis laporan" :options="$reportTypes" required />
                                <x-admin.select name="field_team_id" label="Tim pelaksana" :options="$teams->pluck('name', 'id')->all()" :value="$wo->field_team_id" placeholder="(sesuai WO)" />
                                @if ($wo->isApkuk())
                                    <x-admin.input type="number" name="installed_quantity" label="APKUK terpasang (bh)" min="1" max="1000" hint="Wajib untuk Pemasangan selesai." />
                                @endif
                                <x-admin.input type="datetime-local" name="occurred_at" label="Waktu pelaksanaan (WIB)" :value="$nowWib" required />
                                <x-admin.input name="latitude" label="Latitude" placeholder="-5.20" hint="Opsional bila input Admin." />
                                <x-admin.input name="longitude" label="Longitude" placeholder="105.70" />
                            </div>
                            <fieldset>
                                <legend class="text-xs font-semibold text-slate-700">Petugas aktual <span class="text-red-600">*</span></legend>
                                <div class="mt-1 grid max-h-40 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                                    @forelse ($teams->flatMap->personnel as $person)
                                        <label class="flex items-center gap-2 text-xs text-slate-700"><input type="checkbox" name="personnel_ids[]" value="{{ $person->id }}" @checked(in_array($person->id, old('personnel_ids', []), true))> {{ $person->name }}</label>
                                    @empty
                                        <p class="text-xs text-slate-500">Belum ada petugas aktif pada tim {{ $wo->teamLabel() }}. Tambahkan di Master Data atau isi nama manual.</p>
                                    @endforelse
                                </div>
                            </fieldset>
                            <x-admin.input name="personnel_names" label="Nama petugas lain (pisahkan dengan koma)" hint="Untuk petugas yang belum ada di master." />
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <x-admin.input type="file" name="photos_before[]" label="Foto BEFORE" accept="image/*" multiple />
                                <x-admin.input type="file" name="photos_after[]" label="Foto AFTER" accept="image/*" multiple />
                            </div>
                            <x-admin.textarea name="notes" label="Catatan" rows="2" />
                            <x-admin.button>Simpan laporan</x-admin.button>
                        </form>
                    @endif
                </div>
                <div class="space-y-4">
                    @if ($workable && $escalationTargets !== [])
                        <form method="POST" action="{{ route('admin.work-orders.escalations', $wo) }}" class="space-y-3 rounded-lg border border-slate-200 p-4">
                            @csrf
                            <h3 class="text-sm font-bold text-slate-900">Ajukan limpahan ke tim lain</h3>
                            <p class="text-[11px] text-slate-500">Untuk kebutuhan pemadaman, peralatan khusus, atau kompetensi teknis. Hujan/akses sementara gunakan reschedule. WO sumber tidak dihapus; ACC di Review & Validasi.</p>
                            @if ($escalationReasons === [])
                                <x-admin.notice tone="warning">Belum ada kode <strong>Alasan limpahan</strong>. Tambahkan di <a class="underline" href="{{ route('admin.master-data.index', ['tab' => 'kode']) }}">Master Data → Kode Referensi</a>.</x-admin.notice>
                            @else
                                <x-admin.select name="target" label="Tim & jenis tujuan" :options="$escalationTargets" placeholder="Pilih tujuan" required />
                                <x-admin.select name="reason_code" label="Alasan" :options="$escalationReasons" placeholder="Pilih alasan" required />
                                <x-admin.textarea name="note" label="Catatan" rows="2" required />
                                <x-admin.button variant="secondary">Ajukan limpahan</x-admin.button>
                            @endif
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.work-orders.issues', $wo) }}" class="space-y-3 rounded-lg border border-slate-200 p-4">
                        @csrf
                        <h3 class="text-sm font-bold text-slate-900">Catat kendala / special case</h3>
                        <x-admin.select name="issue_type" label="Jenis kendala" :options="$issueTypes" placeholder="Pilih jenis" required />
                        <x-admin.textarea name="description" label="Uraian" rows="2" required />
                        <x-admin.button variant="secondary">Catat kendala</x-admin.button>
                    </form>

                    @if ($wo->status->canTransitionTo(WorkOrderStatus::Dibatalkan))
                        <div class="rounded-lg border border-red-100 p-4">
                            <h3 class="text-sm font-bold text-slate-900">Batalkan WO</h3>
                            @if ($wo->supportsAdminCancellation())
                                <p class="mt-1 text-[11px] text-slate-500">Pembatalan tercatat di riwayat audit dan melepas kunci lokasi sehingga dapat dibuatkan WO baru.</p>
                                <form method="POST" action="{{ route('admin.work-orders.cancel', $wo) }}" class="mt-3 flex flex-col gap-3 md:flex-row" data-confirm="Batalkan WO {{ $wo->code }}? Tindakan ini tidak dapat dikembalikan.">
                                    @csrf
                                    <input type="text" name="cancel_reason" required minlength="5" maxlength="1000" value="{{ old('cancel_reason') }}" placeholder="Alasan pembatalan (wajib)" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <x-admin.button variant="danger">Batalkan WO</x-admin.button>
                                </form>
                            @else
                                <p class="mt-1 text-[11px] text-slate-500">WO limpahan (child/parent eskalasi) dan WO otomatis sistem belum dapat dibatalkan karena aturan pembatalannya belum disahkan.</p>
                                <div class="mt-3"><x-admin.unavailable-action label="Batalkan WO" variant="secondary" reason="Aturan batal WO limpahan/otomatis belum disahkan." /></div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </x-admin.panel>
    @endif

    <x-admin.panel id="riwayat" title="Riwayat audit" description="Append-only: setiap perubahan status, data, dan keputusan tercatat dengan pelaku dan waktu.">
        <ol class="space-y-3">
            @foreach ($wo->statusLogs as $log)
                <li class="flex gap-3 text-sm">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-800">
                            {{ WorkOrderStatusLog::ACTION_LABELS[$log->action] ?? $log->action }}
                            @if ($log->from_status || $log->to_status)
                                <span class="font-normal text-slate-500">{{ $log->from_status ?? '—' }} → {{ $log->to_status ?? '—' }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-500">{{ $fmt($log->created_at) }} · {{ $log->actor?->displayName() ?? 'Sistem' }}@if (($log->payload['channel'] ?? null) === 'ADMIN_WEB') · input Admin atas nama tim @elseif (($log->payload['channel'] ?? null) === 'MOBILE') · aplikasi lapangan @endif</div>
                        @if ($log->reason)<div class="mt-1 text-xs text-slate-600">Alasan: {{ $log->reason }}</div>@endif
                        @if ($log->action === WorkOrderStatusLog::ACTION_UPDATED && isset($log->payload['after']))
                            <div class="mt-1 text-xs text-slate-600">
                                @foreach ($log->payload['after'] as $key => $value)
                                    <div><code>{{ $key }}</code>: {{ is_scalar($log->payload['before'][$key] ?? null) ? $log->payload['before'][$key] : '—' }} → {{ is_scalar($value) ? $value : '—' }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-admin.panel>
</div>
@endsection
