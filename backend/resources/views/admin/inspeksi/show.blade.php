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

    <x-admin.panel title="Kunjungan">
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
                                        <option value="{{ $value }}">{{ $label }}</option>
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
                @endif
            </div>
        </x-admin.panel>
    @empty
        <x-admin.panel>
            <x-admin.empty-state title="Kunjungan tanpa temuan." description="Hasil 'Tidak Ada Temuan' tercatat jujur dan tidak dihitung sebagai temuan." />
        </x-admin.panel>
    @endforelse
</div>
@endsection
