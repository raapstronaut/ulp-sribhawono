@props([
    'label',
    // Alasan tindakan belum tersedia; tampil sebagai tooltip dan teks pembaca layar.
    'reason',
    'variant' => 'primary',
    'size' => 'md',
])
@php
    $variants = [
        'primary' => 'border-blue-600/20 bg-blue-600/50 text-white',
        'secondary' => 'border-slate-200 bg-slate-100 text-slate-400',
    ];
    $sizes = [
        'md' => 'px-4 py-2 text-sm',
        'sm' => 'px-2 py-1 text-[10px]',
    ];
@endphp
<button type="button" disabled aria-disabled="true" title="{{ $reason }}" {{ $attributes->class(['inline-flex cursor-not-allowed items-center gap-2 rounded-lg border font-semibold opacity-75', $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}>
    {{ $label }}
    <span class="rounded bg-white/30 px-1 text-[9px] font-bold uppercase tracking-wide">Belum tersedia</span>
    <span class="sr-only">: {{ $reason }}</span>
</button>
