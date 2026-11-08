@props([
    'title',
    'description' => null,
])
<div {{ $attributes->class(['flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full border border-slate-100 bg-slate-50">
        <svg class="h-7 w-7 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
    </div>
    <h3 class="mb-1 text-sm font-bold text-slate-700">{{ $title }}</h3>
    @if ($description)
        <p class="max-w-md text-xs text-slate-500">{{ $description }}</p>
    @endif
    {{ $slot }}
</div>
