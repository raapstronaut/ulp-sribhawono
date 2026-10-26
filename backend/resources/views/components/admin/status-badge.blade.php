@props([
    'tone' => 'slate',
])
@php
    $tones = [
        'blue' => 'bg-blue-50 text-blue-700 border-blue-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'amber' => 'bg-amber-50 text-amber-700 border-amber-200',
        'sky' => 'bg-sky-50 text-sky-700 border-sky-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'red' => 'bg-red-50 text-red-700 border-red-200',
        'violet' => 'bg-violet-50 text-violet-700 border-violet-200',
        'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
    ];
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md border px-2 py-0.5 text-[10px] font-bold', $tones[$tone] ?? $tones['slate']]) }}>{{ $slot }}</span>
