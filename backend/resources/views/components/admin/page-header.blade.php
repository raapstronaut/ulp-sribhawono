@props([
    'title',
    'section' => null,
    'parent' => null,
    'parentUrl' => null,
    'description' => null,
    // live = data asli tersedia, partial = sebagian data asli, skeleton = domain belum dibangun
    'state' => null,
])
@php
    $states = [
        'live' => ['label' => 'Data asli · baca saja', 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'partial' => ['label' => 'Sebagian data tersedia', 'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
        'skeleton' => ['label' => 'Kerangka · domain belum dibangun', 'class' => 'bg-slate-100 text-slate-600 border-slate-200'],
    ];
    $stateInfo = $state ? $states[$state] : null;
@endphp
<div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div class="min-w-0">
        @if ($section)
            <nav class="mb-2 flex text-[10px] font-medium text-slate-500" aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-y-1 space-x-1">
                    <li>{{ $section }}</li>
                    @if ($parent)
                        <li aria-hidden="true"><svg class="mx-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                        <li>@if ($parentUrl)<a href="{{ $parentUrl }}" class="hover:text-blue-700">{{ $parent }}</a>@else{{ $parent }}@endif</li>
                    @endif
                    <li aria-hidden="true"><svg class="mx-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                    <li class="text-blue-600" aria-current="page">{{ $title }}</li>
                </ol>
            </nav>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-bold leading-tight text-slate-900">{{ $title }}</h1>
            @if ($stateInfo)
                <span class="rounded-md border px-2 py-0.5 text-[10px] font-bold {{ $stateInfo['class'] }}">{{ $stateInfo['label'] }}</span>
            @endif
        </div>
        @if ($description)
            <p class="mt-1 max-w-4xl text-xs text-slate-500">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
