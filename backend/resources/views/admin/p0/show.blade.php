@extends('layouts.admin')

@section('title', 'P0 '.$item->code)

@section('content')
@php
    use App\Models\P0Item;
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->timezone($tz)->format('d/m/Y H:i').' WIB' : '-';
    $shift = $item->session->shiftInstance;
    $pasilaUrl = route('admin.pasila.show', [
        'posko' => $item->posko_id,
        'work_date' => $shift->operational_date->toDateString(),
        'shift_code' => $shift->shift_code,
    ]);
    $snapshot = $item->source_snapshot ?? [];
    $isPending = $item->approval_status === P0Item::APPROVAL_MENUNGGU;
    $stageTone = match ($item->stage()) {
        P0Item::STAGE_MENUNGGU_ACC => 'amber',
        P0Item::STAGE_MENUNGGU_PENGGANTI => 'red',
        P0Item::STAGE_AKTIF => 'blue',
        P0Item::STAGE_MENUNGGU_VERIFIKASI => 'violet',
        P0Item::STAGE_SELESAI => 'emerald',
        default => 'slate',
    };
@endphp
<div class="mx-auto max-w-7xl space-y-5 pb-10" data-p0-detail-v2>
    <x-admin.posko-tabs />
    <x-admin.page-header
        section="Pekerjaan"
        parent="P0"
        :parent-url="route('admin.p0.index')"
        :title="'Detail P0 · '.$item->code"
        state="live"
        :description="'Pekerjaan POSKO dari sumber yang tercatat dalam sesi PASILA. Tahap P0 dan status WO adalah dua hal berbeda.'"
    >
        <x-slot:actions>
            <a href="{{ route('admin.p0.index') }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700">Kembali ke daftar</a>
            <a href="{{ $pasilaUrl }}" class="inline-flex items-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">Lihat sesi PASILA</a>
            @if ($isPending)
                <a href="#keputusan-admin" class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">Review usulan</a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- P0-DETAIL-V2: ringkasan operasional tanpa membuat atau mengubah transaksi. --}}
    <section class="rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 via-white to-slate-50 p-4 shadow-sm md:p-5" aria-labelledby="p0-summary-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">Detail pekerjaan P0</span>
                    <x-admin.status-badge :tone="$stageTone">{{ $item->stageLabel() }}</x-admin.status-badge>
                </div>
                <h2 id="p0-summary-title" class="break-all text-xl font-bold text-slate-900">{{ $item->code }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ $item->originLabel() }} · dibuat {{ $fmt($item->created_at) }} · sesi {{ $item->session->code }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Status pekerjaan</div>
                @if ($item->workOrder)
                    <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="mt-1 block text-sm font-bold text-blue-700 hover:underline">{{ $item->workOrder->code }}</a>
                    <p class="text-xs text-slate-600">{{ $item->workOrder->status->label() }}</p>
                @else
                    <p class="mt-1 text-sm font-bold text-amber-700">Belum ada WO P0</p>
                    <p class="text-[11px] text-slate-500">Usulan belum menjadi pekerjaan</p>
                @endif
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">POSKO</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ $item->posko->nama_posko }}</p>
                <p class="text-xs text-slate-500">Penanggung jawab pekerjaan</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Section</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ $item->section_name }}</p>
                <p class="text-xs text-slate-500">{{ $item->penyulang->nama_penyulang }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Sesi PASILA sumber</p>
                <a href="{{ $pasilaUrl }}" class="mt-1 block break-all text-xs font-bold text-blue-700 hover:underline">{{ $item->session->code }}</a>
                <p class="text-xs text-slate-500">Shift {{ $shift->shift_code }} · {{ $shift->operational_date->format('d/m/Y') }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Sumber masalah</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ $item->sourceLabel() }}</p>
                <p class="text-xs text-slate-500">{{ $snapshot['finding_code'] ?? 'Mengikuti data sumber' }}</p>
            </div>
        </div>

        @if ($isPending)
            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Usulan SYSTEM ini menunggu keputusan Admin. WO P0 hanya dibuat setelah ACC; menolak usulan akan membuka kewajiban P0 manual pengganti dari sesi PASILA yang sama.</p>
        @elseif ($item->replacement_status === P0Item::REPLACEMENT_WAJIB && ! $item->replacement)
            <p class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800">P0 pengganti masih wajib dibuat. <a href="{{ $pasilaUrl }}#p0" class="underline hover:text-red-950">Buka sesi PASILA untuk memilih sumber yang sah.</a></p>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-admin.panel title="Informasi pekerjaan" description="Ringkasan P0 yang tercatat; bukan data simulasi.">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2" data-p0-detail="{{ $item->stage() }}">
                <x-admin.detail-item label="Tahap"><x-admin.status-badge :tone="$stageTone">{{ $item->stageLabel() }}</x-admin.status-badge></x-admin.detail-item>
                <x-admin.detail-item label="Asal">{{ $item->originLabel() }}{{ $item->createdBy ? ' · '.$item->createdBy->username : '' }}</x-admin.detail-item>
                <x-admin.detail-item label="Posko">{{ $item->posko->nama_posko }}</x-admin.detail-item>
                <x-admin.detail-item label="Section">{{ $item->section_name }}</x-admin.detail-item>
                <x-admin.detail-item label="Penyulang">{{ $item->penyulang->nama_penyulang }}</x-admin.detail-item>
                <x-admin.detail-item label="Dibuat">{{ $fmt($item->created_at) }}</x-admin.detail-item>
                <x-admin.detail-item label="Sesi PASILA"><a href="{{ $pasilaUrl }}" class="font-semibold text-blue-600 hover:underline">{{ $item->session->code }}</a></x-admin.detail-item>
                <x-admin.detail-item label="WO P0">
                    @if ($item->workOrder)
                        <a href="{{ route('admin.work-orders.show', $item->workOrder) }}" class="font-semibold text-blue-600 hover:underline">{{ $item->workOrder->code }}</a> · {{ $item->workOrder->status->label() }}
                    @else
                        Belum ada (usulan bukan pekerjaan)
                    @endif
                </x-admin.detail-item>
            </dl>
            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Uraian pekerjaan</p>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $item->description }}</p>
            </div>
        </x-admin.panel>

        <x-admin.panel title="Asal-usul sumber" :description="$item->sourceLabel()">
            <dl class="grid grid-cols-1 gap-3 text-xs text-slate-700 sm:grid-cols-2" data-p0-source>
                <div><span class="font-semibold text-slate-900">Referensi:</span> {{ $snapshot['session_code'] ?? $snapshot['inspection_code'] ?? '-' }}{{ isset($snapshot['sequence']) ? ' #'.$snapshot['sequence'] : '' }}</div>
                <div><span class="font-semibold text-slate-900">Kode temuan:</span> {{ $snapshot['finding_code'] ?? '-' }}</div>
                <div class="sm:col-span-2"><span class="font-semibold text-slate-900">Uraian temuan:</span> {{ $snapshot['description'] ?? '-' }}</div>
                <div><span class="font-semibold text-slate-900">Section sumber:</span> {{ $snapshot['section'] ?? '-' }}</div>
                <div><span class="font-semibold text-slate-900">Waktu tercatat:</span> {{ $fmt($snapshot['occurred_at'] ?? null) }}{{ isset($snapshot['validated_at']) ? ' · divalidasi '.$fmt($snapshot['validated_at']) : '' }}</div>
                <div><span class="font-semibold text-slate-900">GPS:</span> {{ isset($snapshot['latitude']) ? $snapshot['latitude'].', '.$snapshot['longitude'] : '-' }}</div>
            </dl>
            @if ($item->recommendation)
                <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <summary class="cursor-pointer text-xs font-semibold text-slate-800">Lihat dasar rekomendasi</summary>
                    <div class="mt-3 space-y-1 text-xs text-slate-600">
                        <p class="font-semibold">{{ $item->recommendation['strategy'] ?? '-' }} {{ $item->recommendation['version'] ?? '' }} · {{ ($item->recommendation['is_machine_learning'] ?? false) ? 'model ML' : 'bukan ML' }}</p>
                        @foreach ($item->recommendation['reasons'] ?? [] as $reason)
                            <p>{{ $reason }}</p>
                        @endforeach
                    </div>
                </details>
            @endif
        </x-admin.panel>
    </div>

    <section id="keputusan-admin" class="space-y-3 scroll-mt-24" aria-labelledby="p0-decision-title">
        <div>
            <h2 id="p0-decision-title" class="text-base font-bold text-slate-900">Keputusan Admin & tindak lanjut</h2>
            <p class="mt-1 text-xs text-slate-500">Persetujuan menentukan apakah usulan SYSTEM menjadi WO. P0 manual dibuat melalui PASILA dengan sumber masalah yang sah.</p>
        </div>
        @if ($isPending)
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
            <x-admin.panel title="Keputusan dan pengganti" description="Jejak keputusan tetap terlihat walaupun usulan sudah ditindaklanjuti.">
                <dl class="grid grid-cols-1 gap-4 text-xs text-slate-700 md:grid-cols-3" data-p0-decision="{{ $item->approval_status }}">
                    <div><span class="font-semibold">Persetujuan:</span> {{ $item->approval_status }}{{ $item->reviewedBy ? ' · '.$item->reviewedBy->username.' · '.$fmt($item->reviewed_at) : '' }}</div>
                    <div><span class="font-semibold">Catatan:</span> {{ $item->review_note ?? '-' }}</div>
                    <div><span class="font-semibold">Pengganti:</span>
                        @if ($item->replacement)
                            <a href="{{ route('admin.p0.show', $item->replacement) }}" class="font-semibold text-blue-600 hover:underline">{{ $item->replacement->code }}</a>
                            <span class="block text-[11px] text-slate-500">Kewajiban penggantian terpenuhi. Target P0 shift baru terpenuhi bila P0 pengganti selesai.</span>
                        @elseif ($item->replacement_status === P0Item::REPLACEMENT_WAJIB)
                            <span class="font-semibold text-red-700">Wajib dibuat</span> — <a href="{{ $pasilaUrl }}#p0" class="text-blue-600 hover:underline">buat P0 manual di sesi PASILA</a>
                        @else
                            -
                        @endif
                    </div>
                    @if ($item->replaces)
                        <div class="md:col-span-3"><span class="font-semibold">Mengganti usulan ditolak:</span> <a href="{{ route('admin.p0.show', $item->replaces) }}" class="text-blue-600 hover:underline">{{ $item->replaces->code }}</a></div>
                    @endif
                </dl>
            </x-admin.panel>
        @endif
    </section>

    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
        P0 dihitung sebagai pekerjaan tersendiri dan tidak mengisi kuota enam WO APKUK. Target P0 pada sesi PASILA terpenuhi hanya setelah pekerjaan P0 selesai.
    </div>
    @if (isset($crewAudit) && $crewAudit !== null)
        @include('admin.partials.posko-crew-audit', ['crewAudit' => $crewAudit])
    @else
        <x-admin.panel title="Audit pelaksana WO P0" description="Audit identitas pelaksana baru tersedia setelah P0 memiliki Work Order.">
            <p class="text-sm text-slate-600">Belum ada WO P0 untuk diaudit. Sumber temuan PASILA atau inspeksi tetap tercatat pada panel Asal-usul sumber.</p>
        </x-admin.panel>
    @endif

</div>
@endsection
