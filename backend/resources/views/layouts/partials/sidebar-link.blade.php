@php($isActive = request()->routeIs($item['active']))
<a href="{{ route($item['route']) }}" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors {{ $isActive ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50' }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}" @if ($isActive) aria-current="page" @endif>
    @isset($item['svg'])
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">@foreach ($item['svg'] as $path)<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $path }}"></path>@endforeach</svg>
    @else
        <span class="flex h-5 w-5 shrink-0 items-center justify-center text-[10px] font-black" aria-hidden="true">{{ $item['icon'] }}</span>
    @endisset
    <span class="sidebar-label truncate">{{ $item['label'] }}</span>
</a>
