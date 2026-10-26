@props([
    'paginator',
    'label' => 'data',
])
<div {{ $attributes->class(['flex flex-col items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/30 p-4 sm:flex-row']) }}>
    <div class="text-[10px] leading-tight text-slate-500">
        @if ($paginator->total() > 0)
            Menampilkan <strong class="text-slate-700">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</strong> dari <strong class="text-slate-700">{{ number_format($paginator->total(), 0, ',', '.') }}</strong> {{ $label }}.
        @else
            Menampilkan <strong class="text-slate-700">0</strong> {{ $label }}.
        @endif
    </div>
    @if ($paginator->hasPages())
        <nav class="flex items-center gap-2 text-xs" aria-label="Navigasi halaman {{ $label }}">
            @if ($paginator->onFirstPage())
                <span class="rounded border border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-400">Sebelumnya</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" data-loading-link rel="prev" class="rounded border border-slate-200 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50">Sebelumnya</a>
            @endif
            <span class="text-slate-500">Hal. {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" data-loading-link rel="next" class="rounded border border-slate-200 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50">Berikutnya</a>
            @else
                <span class="rounded border border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-400">Berikutnya</span>
            @endif
        </nav>
    @endif
</div>
