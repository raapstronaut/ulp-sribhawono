@props([
    'workOrder',
])
@php
    $tones = [
        'DITUGASKAN' => 'blue',
        'DIKERJAKAN' => 'indigo',
        'MENUNGGU_VERIFIKASI' => 'amber',
        'RESCHEDULE_DIAJUKAN' => 'amber',
        'DIJADWALKAN_ULANG' => 'sky',
        'SELESAI' => 'emerald',
        'SELESAI_DITERUSKAN' => 'emerald',
        'DIBATALKAN' => 'slate',
    ];
@endphp
<span class="inline-flex flex-wrap items-center gap-1">
    <x-admin.status-badge :tone="$tones[$workOrder->status->value] ?? 'slate'">{{ $workOrder->status->label() }}</x-admin.status-badge>
    @if ($workOrder->isOverdue())
        <x-admin.status-badge tone="red">Terlambat</x-admin.status-badge>
    @endif
</span>
