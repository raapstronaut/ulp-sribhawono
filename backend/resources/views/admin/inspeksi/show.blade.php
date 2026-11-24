@extends('layouts.admin')

@section('title', $inspection->code)

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $tone = ['MENUNGGU_VALIDASI' => 'amber', 'DISETUJUI' => 'emerald', 'DITOLAK' => 'red'];
@endphp
<div class="mx-auto max-w-6xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" parent="Inspeksi" :parent-url="route('admin.inspeksi.index')" :title="$inspection->code"
        :description="($inspection->has_finding ? $inspection->findings->count().' temuan' : 'Tidak ada temuan').' · '.($inspection->source === 'ADMIN' ? 'input Admin' : 'aplikasi lapangan')" />

    <section class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="mb-2 flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wide">
                    <span class="rounded-md bg-blue-600 px-2 py-1 text-white">DETAIL INSPEKSI</span>
                    <span class="rounded-md border border-blue-200 bg-white px-2 py-1 text-blue-700">{{ $inspection->source === 'ADMIN' ? 'Input Admin' : 'Aplikasi lapangan' }}</span>
                </div>
                <h2 class="text-lg font-bold text-slate-900">{{ $inspection->code }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ $inspection->penyulang?->nama_penyulang ?? '-' }} - {{ $inspection->section_name ?? 'Section belum dicatat' }} - {{ $fmt($inspection->visited_at) }}</p>
            </div>
            <a href="{{ route('admin.inspeksi.index') }}" class="inline-flex rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">Kembali ke daftar</a>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Hasil kunjungan</div><div class="mt-1 text-sm font-bold text-slate-900">{{ $inspection->has_finding ? 'Ada temuan' : 'Tanpa temuan' }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Jumlah temuan</div><div class="mt-1 text-xl font-bold text-blue-700">{{ $inspection->findings->count() }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Menunggu validasi</div><div class="mt-1 text-xl font-bold text-amber-700">{{ $inspection->findings->where('validation_status', 'MENUNGGU_VALIDASI')->count() }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3"><div class="text-[10px] font-bold uppercase text-slate-500">Disetujui</div><div class="mt-1 text-xl font-bold text-emerald-700">{{ $inspection->findings->where('validation_status', 'DISETUJUI')->count() }}</div></div>
        </div>
    </section>

    <div class="pln-workspace-panel" data-pln-detail-root data-pln-detail-default="{{ $errors->any() ? 'temuan' : 'kunjungan' }}">
        <div class="pln-panel-title">DETAIL INSPEKSI</div>
        <div class="pln-subtabs" role="tablist" aria-label="Tab detail inspeksi">
            <button type="button" class="pln-subtab" id="pln-tab-kunjungan" role="tab" data-pln-detail-tab="kunjungan" aria-controls="pln-panel-kunjungan" aria-selected="true">Informasi Kunjungan</button>
            <button type="button" class="pln-subtab" id="pln-tab-temuan" role="tab" data-pln-detail-tab="temuan" aria-controls="pln-panel-temuan" aria-selected="false">Temuan &amp; Keputusan ({{ $inspection->findings->count() }})</button>
        </div>
    </div>
    <x-admin.panel id="pln-panel-kunjungan" role="tabpanel" aria-labelledby="pln-tab-kunjungan" data-pln-detail-panel="kunjungan" title="Informasi kunjungan" description="Identitas pelaksana, penyulang, waktu, dan catatan yang tercatat di sistem.">
        <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-3">
            <x-admin.detail-item label="Tim">{{ $inspection->fieldTeam?->name }}</x-admin.detail-item>
            <x-admin.detail-item label="Penyulang">{{ $inspection->penyulang?->nama_penyulang }}</x-admin.detail-item>
            <x-admin.detail-item label="Section / area">{{ $inspection->section_name }}</x-admin.detail-item>
            <x-admin.detail-item label="Waktu kunjungan">{{ $fmt($inspection->visited_at) }}</x-admin.detail-item>
            <x-admin.detail-item label="Petugas">{{ $inspection->personnel->pluck('personnel_name')->implode(', ') }}</x-admin.detail-item>
            <x-admin.detail-item label="Dicatat oleh">{{ $inspection->submittedBy?->displayName() }} · {{ $fmt($inspection->created_at) }}</x-admin.detail-item>
            @if ($inspection->latitude)
                <x-admin.detail-item label="GPS kunjungan">{{ $inspection->latitude }}, {{ $inspection->longitude }}</x-admin.detail-item>
            @endif
        </dl>
        @if ($inspection->summary)
            <p class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700">{{ $inspection->summary }}</p>
        @endif
    </x-admin.panel>

    <div id="pln-panel-temuan" role="tabpanel" aria-labelledby="pln-tab-temuan" data-pln-detail-panel="temuan" class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Temuan dan keputusan Admin</h2>
            <p class="text-xs text-slate-500">Setiap temuan memiliki status validasi tersendiri. Persetujuan atau penolakan tidak dijalankan otomatis.</p>
        </div>
    </div>

    @forelse ($inspection->findings as $finding)
        <x-admin.panel :id="'temuan-'.$finding->id" :title="($findingCodes[$finding->finding_code] ?? $finding->finding_code)">
            <x-slot:actions>
                <x-admin.status-badge :tone="$tone[$finding->validation_status] ?? 'slate'">{{ $finding->validationLabel() }}</x-admin.status-badge>
            </x-slot:actions>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-3 lg:col-span-2">
                    <p class="text-sm text-slate-800">{{ $finding->description }}</p>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <x-admin.detail-item label="Aset">{{ $finding->asset_label ?? ($finding->keypoint ? $finding->keypoint->jenis.' · '.$finding->keypoint->nama : null) }}</x-admin.detail-item>
                        <x-admin.detail-item label="Koordinat">
                            @if ($finding->latitude)<a class="text-blue-600" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $finding->latitude }},{{ $finding->longitude }}">{{ $finding->latitude }}, {{ $finding->longitude }}</a>@endif
                        </x-admin.detail-item>
                        <x-admin.detail-item label="Saran tim">{{ $finding->suggested_team_type ?? 'Belum ada' }}</x-admin.detail-item>
                        <x-admin.detail-item label="WO tindak lanjut">
                            @if ($finding->followUpWorkOrder)<a class="text-blue-600" href="{{ route('admin.work-orders.show', $finding->followUpWorkOrder) }}">{{ $finding->followUpWorkOrder->code }}</a>@endif
                        </x-admin.detail-item>
                    </dl>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($finding->media as $photo)
                            <a href="{{ route('admin.media.inspections', $photo) }}" target="_blank" rel="noopener"><img src="{{ route('admin.media.inspections', $photo) }}" alt="Foto temuan" loading="lazy" class="h-20 w-24 rounded border border-slate-200 object-cover"></a>
                        @empty
                            <span class="text-xs text-slate-500">Tanpa foto.</span>
                        @endforelse
                    </div>
                    @if ($finding->validated_at)
                        <p class="text-xs text-slate-500">Divalidasi {{ $fmt($finding->validated_at) }} oleh {{ $finding->validatedBy?->displayName() }}@if ($finding->validation_note): “{{ $finding->validation_note }}”@endif</p>
                    @endif
                </div>
                @if ($finding->validation_status === 'MENUNGGU_VALIDASI')
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.inspeksi.findings.approve', $finding) }}" class="space-y-2 rounded-lg border border-emerald-100 p-3" data-confirm="Setujui temuan ini?">
                            @csrf
                            <p class="text-xs font-bold text-slate-800">ACC temuan</p>
                            <label class="block text-[11px] font-semibold text-slate-600">WO tindak lanjut (opsional)
                                <select name="follow_up" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Tanpa WO</option>
                                    @foreach ($followUpTypes as $value => $label)
                                        @if (! $finding->suggested_team_type || str_starts_with($value, $finding->suggested_team_type.'|'))
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-[11px] font-semibold text-slate-600">Tim pelaksana
                                <select name="field_team_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Semua tim sesuai jenis</option>
                                    @foreach ($teamsByRole as $role => $teams)
                                        <optgroup label="{{ $role }}">
                                            @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="text-[11px] font-semibold text-slate-600">Tanggal kerja<input type="date" name="work_date" value="{{ $today }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                                <label class="text-[11px] font-semibold text-slate-600">Target selesai<input type="date" name="due_date" value="{{ $today }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                            </div>
                            <input type="text" name="review_note" maxlength="1000" placeholder="Catatan (opsional)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                            <x-admin.button variant="success" size="sm" class="w-full">ACC temuan</x-admin.button>
                        </form>
                        <form method="POST" action="{{ route('admin.inspeksi.findings.reject', $finding) }}" class="space-y-2 rounded-lg border border-red-100 p-3">
                            @csrf
                            <input type="text" name="review_note" required minlength="5" maxlength="1000" placeholder="Alasan penolakan (wajib)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                            <x-admin.button variant="danger" size="sm" class="w-full">Tolak temuan</x-admin.button>
                        </form>
                    </div>
                @elseif ($finding->validation_status === 'DISETUJUI' && $finding->follow_up_work_order_id === null && $finding->isApprovedPoskoApkukTarget())
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.inspeksi.findings.posko-work-order', $finding) }}" class="space-y-2 rounded-lg border border-blue-200 bg-blue-50/40 p-3" data-confirm="Buat WO manual POSKO dari temuan APKUK yang telah disetujui?">
                            @csrf
                            <p class="text-xs font-bold text-slate-800">Buat WO POSKO dari INSPEKSI</p>
                            <p class="text-[11px] text-slate-600">No. tiang {{ $finding->apkuk_no_tiang }} · Kebutuhan {{ $finding->apkuk_target_quantity }} bh. Generator lama belum memisahkan kuota manual dan otomatis; jangan diaktifkan sebelum versi baru selesai.</p>
                            <label class="block text-[11px] font-semibold text-slate-600">POSKO penanggung jawab
                                <select name="posko_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Pilih POSKO</option>
                                    @foreach ($poskoOptions as $id => $name)
                                        <option value="{{ $id }}" @selected(old('posko_id') === $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-[11px] font-semibold text-slate-600">Tim pelaksana POSKO (opsional)
                                <select name="field_team_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Belum ditentukan</option>
                                    @foreach ($teamsByRole[\App\Models\WorkOrder::TEAM_POSKO] ?? [] as $id => $name)
                                        <option value="{{ $id }}" @selected(old('field_team_id') === $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="text-[11px] font-semibold text-slate-600">Tanggal operasional<input type="date" name="work_date" required value="{{ old('work_date', $today) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                                <label class="text-[11px] font-semibold text-slate-600">Shift
                                    <select name="shift_code" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                        @foreach ($shiftOptions as $shift)
                                            <option value="{{ $shift }}" @selected(old('shift_code') === $shift)>Shift {{ $shift }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <label class="block text-[11px] font-semibold text-slate-600">Catatan WO (opsional)
                                <textarea name="notes" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">{{ old('notes') }}</textarea>
                            </label>
                            <x-admin.button size="sm" class="w-full">Buat WO POSKO</x-admin.button>
                        </form>
                    </div>
                @elseif ($finding->validation_status === 'DISETUJUI' && $finding->follow_up_work_order_id === null)
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.inspeksi.findings.work-order', $finding) }}" class="space-y-2 rounded-lg border border-blue-200 bg-blue-50/40 p-3" data-confirm="Buat WO dari temuan yang sudah disetujui ini?">
                            @csrf
                            <p class="text-xs font-bold text-slate-800">Buat WO dari temuan disetujui</p>
                            <p class="text-[11px] text-slate-600">HARSUS/ROW saja pada tahap ini. Temuan POSKO menunggu integrasi target inspeksi tersendiri.</p>
                            <label class="block text-[11px] font-semibold text-slate-600">Jenis WO
                                <select name="follow_up" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Pilih pekerjaan</option>
                                    @foreach ($followUpTypes as $value => $label)
                                        @if (! $finding->suggested_team_type || str_starts_with($value, $finding->suggested_team_type.'|'))
                                            <option value="{{ $value }}" @selected(old('follow_up') === $value)>{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-[11px] font-semibold text-slate-600">Tim pelaksana (opsional)
                                <select name="field_team_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">Belum ditentukan</option>
                                    @foreach ($teamsByRole as $role => $teams)
                                        <optgroup label="{{ $role }}">
                                            @foreach ($teams as $id => $name)<option value="{{ $id }}" @selected(old('field_team_id') === $id)>{{ $name }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="text-[11px] font-semibold text-slate-600">Tanggal kerja<input type="date" name="work_date" required value="{{ old('work_date', $today) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                                <label class="text-[11px] font-semibold text-slate-600">Target selesai<input type="date" name="due_date" required value="{{ old('due_date', $today) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                            </div>
                            <label class="block text-[11px] font-semibold text-slate-600">Catatan WO (opsional)
                                <textarea name="notes" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">{{ old('notes') }}</textarea>
                            </label>
                            <x-admin.button size="sm" class="w-full">Buat WO</x-admin.button>
                        </form>
                    </div>
                @endif
            </div>
        </x-admin.panel>
    @empty
        <x-admin.panel>
            <x-admin.empty-state title="Kunjungan tanpa temuan." description="Hasil 'Tidak Ada Temuan' tercatat jujur dan tidak dihitung sebagai temuan." />
        </x-admin.panel>
    @endforelse
    </div>
</div>
@endsection

@section('scripts')
    @include('layouts.partials.work-detail-tabs-script')
@endsection
