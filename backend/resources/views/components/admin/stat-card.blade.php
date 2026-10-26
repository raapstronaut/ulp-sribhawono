@props([
    'label',
    // null berarti data belum tersedia (ditampilkan "—"), bukan bernilai 0.
    'value' => null,
    'hint' => null,
    'href' => null,
    'tone' => 'slate',
    'unavailableLabel' => 'Belum tersedia',
])
@php
    $valueClasses = [
        'slate' => 'text-slate-800',
        'red' => 'text-red-600',
        'amber' => 'text-amber-600',
        'emerald' => 'text-emerald-700',
        'blue' => 'text-blue-700',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm', 'transition hover:border-blue-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500' => $href]) }}>
    <span class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">{{ $label }}</span>
    @if ($value === null)
        <div class="flex items-end justify-between gap-2">
            <span class="text-3xl font-black leading-none text-slate-300">—</span>
            <span class="rounded bg-amber-50 px-2 py-1 text-[9px] font-semibold text-amber-700">{{ $unavailableLabel }}</span>
        </div>
    @else
        <div class="text-3xl font-black leading-none {{ $valueClasses[$tone] ?? $valueClasses['slate'] }}">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : $value }}</div>
    @endif
    @if ($hint)
        <div class="mt-3 text-[10px] leading-snug text-slate-500">{{ $hint }}</div>
    @endif
</{{ $tag }}>
