@extends('layouts.admin')

@section('title', 'P0 '.$item->code)

@section('content')
@php
    use App\Models\P0Item;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $shift = $item->session->shiftInstance;
    $pasilaUrl = route('admin.pasila.show', ['posko' => $item->posko_id, 'work_date' => $shift->operational_date->toDateString(), 'shift_code' => $shift->shift_code]);
    $snapshot = $item->source_snapshot;
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <x-admin.posko-tabs />
    <x-admin.page-header section="Pekerjaan" parent="P0" :parent-url="route('admin.p0.index')" :title="$item->code" state="live"
        :description="$item->originLabel().' · '.$item->stageLabel().' · sesi PASILA '.$item->session->code" />

    <x-admin.panel title="P0" description="Pekerjaan POSKO; Section dan Penyulang diambil dari sumber dan terverifikasi milik Posko sesi.">
        <dl class="grid grid-cols-2 gap-5 text-sm md:grid-cols-4" data-p0-detail="{{ $item->stage() }}">
            <x-admin.detail-item label="Tahap"><x-admin.status-badge tone="blue">{{ $item->stageLabel() }}</x-admin.status-badge></x-admin.detail-item>
            <x-admin.detail-item label="Asal">{{ $item->originLabel() }}{{ $item->createdBy ? ' · '.$item->createdBy->username : '' }}</x-admin.detail-item>
            <x-admin.detail-item label="Sesi PASILA"><a href="{{ $pasilaUrl }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->session->code }}</a></x-admin.detail-item>
            <x-admin.detail-item label="Posko">{{ $item->posko->nama_posko }}</x-admin.detail-item>
            <x-admin.detail-item label="Section">{{ $item->section_name }}</x-admin.detail-item>
            <x-admin.detail-item label="Penyulang">{{ $item->penyulang->nama_penyulang }}</x-admin.detail-item>
            <x-admin.detail-item label="Dibuat">{{ $fmt($item->created_at) }}</x-admin.detail-item>
            <x-admin.detail-item label="WO P0">
                @if ($item->workOrder)
                    <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->workOrder->code }}</a> · {{ $item->workOrder->status->label() }}
                @else
                    Belum ada (usulan bukan pekerjaan)
                @endif
            </x-admin.detail-item>
        </dl>
        <p class="mt-4 text-sm text-slate-700"><span class="font-semibold">Uraian pekerjaan:</span> {{ $item->description }}</p>
    </x-admin.panel>

    <x-admin.panel title="Asal-usul sumber" :description="$item->sourceLabel()">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-xs text-slate-700 md:grid-cols-2" data-p0-source>
            <div><span class="font-semibold">Referensi:</span> {{ $snapshot['session_code'] ?? $snapshot['inspection_code'] ?? '-' }}{{ isset($snapshot['sequence']) ? ' #'.$snapshot['sequence'] : '' }}</div>
            <div><span class="font-semibold">Kode temuan:</span> {{ $snapshot['finding_code'] ?? '-' }}</div>
            <div class="md:col-span-2"><span class="font-semibold">Uraian temuan:</span> {{ $snapshot['description'] ?? '-' }}</div>
            <div><span class="font-semibold">Section sumber:</span> {{ $snapshot['section'] ?? '-' }}</div>
            <div><span class="font-semibold">Waktu tercatat:</span> {{ $fmt($snapshot['occurred_at'] ?? null) }}{{ isset($snapshot['validated_at']) ? ' · divalidasi '.$fmt($snapshot['validated_at']) : '' }}</div>
            <div><span class="font-semibold">GPS:</span> {{ isset($snapshot['latitude']) ? $snapshot['latitude'].', '.$snapshot['longitude'] : '-' }}</div>
        </dl>
        @if ($item->recommendation)
            <div class="mt-3 text-xs text-slate-600">
                <p class="font-semibold">Rekomendasi: {{ $item->recommendation['strategy'] ?? '-' }} {{ $item->recommendation['version'] ?? '' }} · {{ ($item->recommendation['is_machine_learning'] ?? false) ? 'model ML' : 'bukan ML' }}</p>
                @foreach ($item->recommendation['reasons'] ?? [] as $reason)
                    <p>{{ $reason }}</p>
                @endforeach
            </div>
        @endif
    </x-admin.panel>

    @if ($item->approval_status === P0Item::APPROVAL_MENUNGGU)
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-admin.panel title="ACC usulan" description="Membuat WO P0 untuk POSKO. Sumber dan kepemilikan Section diperiksa ulang saat ACC.">
                <form method="POST" action="{{ route('admin.p0.approve', $item) }}" class="space-y-3" data-confirm="ACC usulan {{ $item->code }} dan buat WO P0?">
                    @csrf
                    <x-admin.input type="date" name="work_date" label="Tanggal operasional" :value="$schedule['work_date']" required />
                    <x-admin.select name="shift_code" label="Shift" :options="$shiftOptions" :value="$schedule['shift_code']" required />
                    <x-admin.textarea name="review_note" label="Catatan (opsional)" />
                    <x-admin.button>ACC dan buat WO P0</x-admin.button>
                </form>
            </x-admin.panel>
            <x-admin.panel title="Tolak usulan" description="Usulan tidak menjadi pekerjaan. Penolakan menimbulkan kewajiban P0 manual pengganti dalam sesi PASILA yang sama.">
                <form method="POST" action="{{ route('admin.p0.reject', $item) }}" class="space-y-3">
                    @csrf
                    <x-admin.textarea name="review_note" label="Alasan penolakan" required hint="Minimal 10 karakter." />
                    <x-admin.button variant="danger">Tolak usulan</x-admin.button>
                </form>
            </x-admin.panel>
        </div>
    @else
        <x-admin.panel title="Keputusan dan pengganti">
            <dl class="grid grid-cols-1 gap-3 text-xs text-slate-700 md:grid-cols-3" data-p0-decision="{{ $item->approval_status }}">
                <div><span class="font-semibold">Persetujuan:</span> {{ $item->approval_status }}{{ $item->reviewedBy ? ' · '.$item->reviewedBy->username.' · '.$fmt($item->reviewed_at) : '' }}</div>
                <div><span class="font-semibold">Catatan:</span> {{ $item->review_note ?? '-' }}</div>
                <div><span class="font-semibold">Pengganti:</span>
                    @if ($item->replacement)
                        <a href="{{ route('admin.p0.show', $item->replacement) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ $item->replacement->code }}</a>
                        <span class="block text-[11px] text-slate-500">Kewajiban penggantian terpenuhi. Target P0 shift baru terpenuhi bila P0 pengganti selesai.</span>
                    @elseif ($item->replacement_status === P0Item::REPLACEMENT_WAJIB)
                        <span class="font-semibold text-red-700">Wajib dibuat</span> — <a href="{{ $pasilaUrl }}#p0" class="text-blue-600 hover:text-blue-800">buat P0 manual di sesi PASILA</a>
                    @else
                        -
                    @endif
                </div>
                @if ($item->replaces)
                    <div class="md:col-span-3"><span class="font-semibold">Mengganti usulan ditolak:</span> <a href="{{ route('admin.p0.show', $item->replaces) }}" class="text-blue-600 hover:text-blue-800">{{ $item->replaces->code }}</a></div>
                @endif
            </dl>
        </x-admin.panel>
    @endif
</div>
@endsection
