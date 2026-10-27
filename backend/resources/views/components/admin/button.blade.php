@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'submit',
    'size' => 'md',
])
@php
    $variants = [
        'primary' => 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700',
        'secondary' => 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        'danger' => 'border-red-300 bg-white text-red-700 hover:bg-red-50',
        'success' => 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700',
    ];
    $sizes = ['md' => 'px-4 py-2 text-sm', 'sm' => 'px-3 py-1.5 text-xs'];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-lg border font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60 '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
