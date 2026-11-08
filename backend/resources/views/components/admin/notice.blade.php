@props([
    'tone' => 'info',
    'title' => null,
])
@php
    $tones = [
        'info' => ['box' => 'border-blue-100 bg-blue-50 text-blue-800', 'icon' => 'text-blue-600'],
        'warning' => ['box' => 'border-amber-200 bg-amber-50 text-amber-900', 'icon' => 'text-amber-600'],
    ];
    $toneClasses = $tones[$tone] ?? $tones['info'];
@endphp
<div {{ $attributes->class(['flex gap-3 rounded-xl border p-4 shadow-sm', $toneClasses['box']]) }}>
    <svg class="mt-0.5 h-5 w-5 shrink-0 {{ $toneClasses['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
    <div class="max-w-5xl text-xs leading-relaxed">
        @if ($title)
            <strong class="font-semibold">{{ $title }}</strong>
        @endif
        {{ $slot }}
    </div>
</div>
