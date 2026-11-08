@props([
    'workOrders',
    'emptyTitle' => 'Belum ada Work Order.',
    'emptyDescription' => null,
])
@php
    $tz = \App\Services\ShiftService::TIMEZONE;
    $sourceLabels = [
        \App\Models\WorkOrder::SOURCE_ADMIN_MANUAL => 'Manual Admin',
        \App\Models\WorkOrder::SOURCE_SYSTEM => 'Otomatis sistem',
        \App\Models\WorkOrder::SOURCE_ESCALATION => 'Limpahan (eskalasi)',
    ];
@endphp
<x-admin.table min-width="min-w-[960px]">
    <x-slot:head>
        <th class="px-4 py-3">WO</th>
        <th class="px-4 py-3">Jenis</th>
        <th class="px-4 py-3">Posko</th>
        <th class="px-4 py-3">Penyulang / Section</th>
        <th class="px-4 py-3">Jadwal</th>
        <th class="px-4 py-3">Status</th>
        <th class="px-4 py-3 text-right">Pembaruan</th>
        <th class="px-4 py-3 text-center">Aksi</th>
    </x-slot:head>
    @forelse ($workOrders as $wo)
        <tr class="hover:bg-slate-50/60">
            <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $wo->code }}</div>
                <div class="text-[10px] text-slate-500">{{ $sourceLabels[$wo->source] ?? $wo->source }}</div>
                @if ($wo->parent)
                    <a href="{{ route('admin.work-orders.show', $wo->parent) }}" class="text-[10px] font-semibold text-blue-600 hover:text-blue-800">Asal: {{ $wo->parent->code }}</a>
                @endif
            </td>
            <td class="px-4 py-3">
                <div class="text-xs font-semibold text-slate-800">{{ $wo->work_type }}</div>
                <div class="text-[10px] text-slate-500">{{ $wo->team_type }}</div>
            </td>
            <td class="px-4 py-3">{{ $wo->posko?->nama_posko ?? '-' }}</td>
            <td class="px-4 py-3">
                <div class="font-medium text-slate-800">{{ $wo->penyulang?->nama_penyulang ?? '-' }}</div>
                <div class="text-[10px] text-slate-500">{{ $wo->section_name ?? 'Section belum diketahui' }}</div>
            </td>
            <td class="px-4 py-3">
                <div>{{ $wo->work_date?->format('d/m/Y') ?? '-' }}</div>
                <div class="text-[10px] text-slate-500">{{ $wo->shift_code ? 'Shift '.$wo->shift_code : 'Tanpa shift' }}</div>
            </td>
            <td class="px-4 py-3"><x-admin.wo-status :work-order="$wo" /></td>
            <td class="px-4 py-3 text-right text-xs">{{ $wo->updated_at?->timezone($tz)->format('d/m/Y H:i') }} WIB</td>
            <td class="px-4 py-3 text-center">
                <a href="{{ route('admin.work-orders.show', $wo) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">Detail</a>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="8">
                <x-admin.empty-state :title="$emptyTitle" :description="$emptyDescription" />
            </td>
        </tr>
    @endforelse
</x-admin.table>
