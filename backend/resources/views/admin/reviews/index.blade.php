@extends('layouts.admin')

@section('title', 'Review & Validasi')

@section('content')
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d/m/Y H:i') : '-';
    $categories = \App\Http\Controllers\Admin\ReviewController::CATEGORIES;
@endphp
<div class="mx-auto max-w-7xl space-y-6 pb-12">
    <x-admin.page-header section="Kontrol" title="Review & Validasi" state="live"
        description="Antrean keputusan Admin. Setiap ACC/Tolak mencatat Admin, waktu, dan alasan; status WO berubah sesuai lifecycle (laporan → Selesai/Ditugaskan, reschedule → Dijadwalkan Ulang/status semula, limpahan → child WO + Selesai Diteruskan)." />

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($categories as $key => $label)
            <x-admin.stat-card :label="$label" :value="$load->failed('count_'.$key) ? null : $counts[$key]" :href="route('admin.reviews.index', ['category' => $key])" :tone="($counts[$key] ?? 0) > 0 ? 'amber' : 'slate'" />
        @endforeach
    </div>

    <x-admin.panel flush>
        <x-admin.filter-bar :action="route('admin.reviews.index')" :reset-url="route('admin.reviews.index')" :active="$hasFilter" note="Filter tim/POSKO berlaku untuk antrean WO; temuan inspeksi tidak terikat tim WO.">
            <x-admin.filter-select name="category" label="Kategori" :options="$categories" :value="$filters['category']" placeholder="Semua kategori" />
            <x-admin.filter-select name="team_type" label="Tim" :options="\App\Http\Controllers\Admin\ReviewController::TEAM_TYPES" :value="$filters['team_type']" placeholder="Semua tim" />
            <x-admin.filter-select name="posko_id" label="POSKO" :options="$poskoOptions" :value="$filters['posko_id']" placeholder="Semua POSKO" />
        </x-admin.filter-bar>
    </x-admin.panel>

    @if ($show['reports'])
        <x-admin.panel flush title="Laporan menunggu verifikasi" description="Temuan APKUK sudah lengkap dan penyelesaian WO HARSUS/ROW. Periksa bukti foto sebelum ACC.">
            @if ($load->failed('reports'))
                <x-admin.error-state />
            @elseif ($reports->isEmpty())
                <x-admin.empty-state title="Tidak ada laporan yang menunggu verifikasi." />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($reports as $report)
                        @php $wo = $report->workOrder; @endphp
                        <article class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.work-orders.show', $wo) }}" class="font-bold text-blue-700 hover:text-blue-900">{{ $wo->code }}</a>
                                    <x-admin.status-badge tone="amber">{{ $report->typeLabel() }}</x-admin.status-badge>
                                    <span class="text-xs text-slate-500">{{ $wo->teamLabel() }} · {{ $wo->posko?->nama_posko ?? '-' }} · {{ $wo->targetLabel() ?? '-' }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">Dilaksanakan {{ $fmt($report->occurred_at) }} WIB · dikirim {{ $report->submittedByTeam?->name ?? 'Admin' }} · petugas {{ $report->personnel->pluck('personnel_name')->implode(', ') ?: '-' }}</p>
                                @if ($report->notes)<p class="mt-2 text-sm text-slate-700">{{ $report->notes }}</p>@endif
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @forelse ($report->media as $photo)
                                        <a href="{{ route('admin.media.work-orders', $photo) }}" target="_blank" rel="noopener" class="block w-20 text-center">
                                            <img src="{{ route('admin.media.work-orders', $photo) }}" alt="Foto {{ $photo->photo_type }}" loading="lazy" class="h-16 w-20 rounded border border-slate-200 object-cover">
                                            <span class="text-[10px] font-semibold text-slate-500">{{ $photo->photo_type }}</span>
                                        </a>
                                    @empty
                                        <span class="text-xs text-red-600">Tanpa foto bukti.</span>
                                    @endforelse
                                </div>
                            </div>
                            <div class="space-y-2">
                                <form method="POST" action="{{ route('admin.reviews.reports.approve', $report) }}" class="space-y-2" data-confirm="Setujui laporan {{ $wo->code }}? WO akan berstatus Selesai.">
                                    @csrf
                                    <input type="text" name="review_note" maxlength="1000" placeholder="Catatan ACC (opsional)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                    <x-admin.button variant="success" size="sm" class="w-full">ACC — Selesai</x-admin.button>
                                </form>
                                <form method="POST" action="{{ route('admin.reviews.reports.reject', $report) }}" class="space-y-2" data-confirm="Tolak laporan {{ $wo->code }}? WO dikembalikan ke Ditugaskan.">
                                    @csrf
                                    <input type="text" name="review_note" required minlength="5" maxlength="1000" placeholder="Alasan penolakan (wajib)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                    <x-admin.button variant="danger" size="sm" class="w-full">Tolak / kembalikan</x-admin.button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
                <x-admin.pagination :paginator="$reports" label="laporan" />
            @endif
        </x-admin.panel>
    @endif

    @if ($show['reschedules'])
        <x-admin.panel flush title="Pengajuan reschedule" description="ACC memindahkan jadwal WO ke tanggal/shift usulan; tolak mengembalikan WO ke status kerja semula.">
            @if ($load->failed('reschedules'))
                <x-admin.error-state />
            @elseif ($reschedules->isEmpty())
                <x-admin.empty-state title="Tidak ada pengajuan reschedule yang menunggu." />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($reschedules as $reschedule)
                        @php $wo = $reschedule->workOrder; @endphp
                        <article class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
                            <div class="lg:col-span-2 text-sm">
                                <a href="{{ route('admin.work-orders.show', $wo) }}" class="font-bold text-blue-700 hover:text-blue-900">{{ $wo->code }}</a>
                                <span class="ml-2 text-xs text-slate-500">{{ $wo->teamLabel() }} · {{ $wo->posko?->nama_posko ?? '-' }} · diajukan {{ $reschedule->requestedByTeam?->name ?? 'Admin' }} {{ $fmt($reschedule->created_at) }}</span>
                                <p class="mt-1 text-xs text-slate-600"><strong>{{ $rescheduleReasons[$reschedule->reason_code] ?? $reschedule->reason_code }}</strong> — {{ $reschedule->note }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $reschedule->from_work_date?->format('d/m/Y') }} {{ $reschedule->from_shift_code }} → <strong>{{ $reschedule->proposed_work_date?->format('d/m/Y') }} {{ $reschedule->proposed_shift_code }}</strong></p>
                            </div>
                            <div class="space-y-2">
                                <form method="POST" action="{{ route('admin.reviews.reschedules.approve', $reschedule) }}" class="space-y-2">
                                    @csrf
                                    <input type="text" name="review_note" maxlength="1000" placeholder="Catatan ACC (opsional)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                    <x-admin.button variant="success" size="sm" class="w-full">ACC — jadwalkan ulang</x-admin.button>
                                </form>
                                <form method="POST" action="{{ route('admin.reviews.reschedules.reject', $reschedule) }}" class="space-y-2">
                                    @csrf
                                    <input type="text" name="review_note" required minlength="5" maxlength="1000" placeholder="Alasan penolakan (wajib)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                    <x-admin.button variant="danger" size="sm" class="w-full">Tolak</x-admin.button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
                <x-admin.pagination :paginator="$reschedules" label="pengajuan" />
            @endif
        </x-admin.panel>
    @endif

    @if ($show['escalations'])
        <x-admin.panel flush title="Permintaan limpahan" description="ACC membuat child WO pada tim tujuan dan menandai WO sumber Selesai Diteruskan secara atomik. Tolak tidak membuat child WO.">
            @if ($load->failed('escalations'))
                <x-admin.error-state />
            @elseif ($escalations->isEmpty())
                <x-admin.empty-state title="Tidak ada permintaan limpahan yang menunggu." />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($escalations as $escalation)
                        @php $wo = $escalation->sourceWorkOrder; @endphp
                        <article class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
                            <div class="text-sm lg:col-span-1">
                                <a href="{{ route('admin.work-orders.show', $wo) }}" class="font-bold text-blue-700 hover:text-blue-900">{{ $wo->code }}</a>
                                <span class="ml-1 text-xs text-slate-500">{{ $wo->teamLabel() }} · {{ $wo->status->label() }}</span>
                                <p class="mt-1 text-xs">Ke <strong>{{ $escalation->target_team_type }} · {{ \App\Models\WorkOrder::WORK_TYPE_LABELS[$escalation->target_work_type] ?? $escalation->target_work_type }}</strong></p>
                                <p class="mt-1 text-xs text-slate-600"><strong>{{ $escalationReasons[$escalation->reason_code] ?? $escalation->reason_code }}</strong> — {{ $escalation->note }}</p>
                                <p class="mt-1 text-[11px] text-slate-500">Diajukan {{ $escalation->requestedByTeam?->name ?? $escalation->requestedBy?->displayName() }} · {{ $fmt($escalation->created_at) }}</p>
                            </div>
                            <form method="POST" action="{{ route('admin.reviews.escalations.approve', $escalation) }}" class="grid grid-cols-2 gap-2 lg:col-span-1" data-confirm="Setujui limpahan {{ $wo->code }}? Child WO dibuat dan WO sumber ditutup sebagai Selesai Diteruskan.">
                                @csrf
                                <label class="col-span-2 text-[11px] font-semibold text-slate-600">Tim pelaksana child WO
                                    <select name="field_team_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                        <option value="">Semua tim {{ $escalation->target_team_type }}</option>
                                        @foreach ($teamsByRole[$escalation->target_team_type] ?? [] as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-[11px] font-semibold text-slate-600">Tanggal kerja<input type="date" name="work_date" value="{{ $today }}" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                                <label class="text-[11px] font-semibold text-slate-600">Target selesai<input type="date" name="due_date" value="{{ $today }}" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"></label>
                                <input type="text" name="review_note" maxlength="1000" placeholder="Catatan ACC (opsional)" class="col-span-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                <x-admin.button variant="success" size="sm" class="col-span-2">ACC — buat child WO</x-admin.button>
                            </form>
                            <form method="POST" action="{{ route('admin.reviews.escalations.reject', $escalation) }}" class="space-y-2">
                                @csrf
                                <input type="text" name="review_note" required minlength="5" maxlength="1000" placeholder="Alasan penolakan (wajib)" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                <x-admin.button variant="danger" size="sm" class="w-full">Tolak limpahan</x-admin.button>
                            </form>
                        </article>
                    @endforeach
                </div>
                <x-admin.pagination :paginator="$escalations" label="permintaan" />
            @endif
        </x-admin.panel>
    @endif

    @if ($show['findings'])
        <x-admin.panel flush title="Temuan inspeksi menunggu validasi" description="Validasi (ACC/Tolak) dan WO tindak lanjut dilakukan di halaman detail inspeksi.">
            @if ($findings === null)
                <x-admin.empty-state title="Temuan inspeksi tidak difilter per tim/POSKO." description="Hapus filter tim/POSKO untuk melihat antrean temuan." />
            @elseif ($load->failed('findings'))
                <x-admin.error-state />
            @elseif ($findings->isEmpty())
                <x-admin.empty-state title="Tidak ada temuan yang menunggu validasi." />
            @else
                <x-admin.table>
                    <x-slot:head>
                        <th class="px-4 py-3">Inspeksi</th><th class="px-4 py-3">Jenis temuan</th><th class="px-4 py-3">Uraian</th><th class="px-4 py-3">Penyulang</th><th class="px-4 py-3">Foto</th><th class="px-4 py-3"></th>
                    </x-slot:head>
                    @foreach ($findings as $finding)
                        <tr>
                            <td class="px-4 py-2 text-xs font-semibold">{{ $finding->inspection->code }}<div class="font-normal text-slate-500">{{ $fmt($finding->inspection->visited_at) }}</div></td>
                            <td class="px-4 py-2 text-xs">{{ $findingCodes[$finding->finding_code] ?? $finding->finding_code }}</td>
                            <td class="px-4 py-2 text-xs">{{ \Illuminate\Support\Str::limit($finding->description, 80) }}</td>
                            <td class="px-4 py-2 text-xs">{{ $finding->inspection->penyulang?->nama_penyulang ?? '-' }}</td>
                            <td class="px-4 py-2 text-xs">{{ $finding->media->count() }}</td>
                            <td class="px-4 py-2 text-right"><a class="text-xs font-semibold text-blue-600 hover:text-blue-800" href="{{ route('admin.inspeksi.show', $finding->inspection) }}#temuan-{{ $finding->id }}">Validasi</a></td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$findings" label="temuan" />
            @endif
        </x-admin.panel>
    @endif
</div>
@endsection
