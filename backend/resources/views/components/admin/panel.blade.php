@props([
    'title' => null,
    'description' => null,
    // flush = tanpa padding isi, untuk tabel dan filter bar.
    'flush' => false,
])
<section {{ $attributes->class(['flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title || isset($actions))
        <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-sm font-bold text-slate-900">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-[10px] leading-snug text-slate-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div @class(['flex-1', 'p-4' => ! $flush])>
        {{ $slot }}
    </div>
</section>
