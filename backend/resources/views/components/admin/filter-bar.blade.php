@props([
    'action',
    'resetUrl' => null,
    // true bila ada filter terisi, agar tautan Reset ditampilkan.
    'active' => false,
    // Filter untuk domain yang belum punya data: kontrol tampil tetapi nonaktif.
    'disabled' => false,
    'note' => null,
    'title' => null,
    'buttonLabel' => 'Terapkan',
    'alwaysReset' => false,
])
<form method="GET" action="{{ $action }}" @unless ($disabled) data-loading-form @endunless {{ $attributes->class(['flex flex-col gap-3 border-b border-slate-200 bg-slate-50/50 p-4 lg:flex-row lg:items-end', 'lg:flex-wrap' => $title !== null]) }} @if ($disabled) aria-disabled="true" @endif>
    @if ($title)
        <h3 class="pln-operations-filter-title">{{ $title }}</h3>
    @endif
    <div class="pln-operations-filter-fields grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {{ $slot }}
    </div>
    <div class="pln-operations-filter-actions flex shrink-0 flex-wrap items-center gap-2">
        @if ($disabled)
            <span class="rounded bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Filter belum aktif</span>
        @else
            <button type="submit" data-loading-label="Memuat…" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-wait disabled:opacity-70">{{ $buttonLabel }}</button>
            @if (($active || $alwaysReset) && $resetUrl)
                <a href="{{ $resetUrl }}" class="px-2 text-sm font-semibold text-blue-600 hover:text-blue-800">Reset</a>
            @endif
        @endif
    </div>
    @if ($note)
        <p class="text-[10px] leading-tight text-slate-400 lg:max-w-56">{{ $note }}</p>
    @endif
</form>
