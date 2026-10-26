@props([
    'title',
    'description',
    // Prasyarat yang belum terpenuhi (tabel domain, aturan TBD, data resmi).
    'requirements' => [],
])
<x-admin.panel :title="$title" {{ $attributes }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
        <span class="w-fit shrink-0 rounded bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Belum tersedia</span>
        <div class="min-w-0 text-xs leading-relaxed text-slate-600">
            <p>{{ $description }}</p>
            @if ($requirements !== [])
                <ul class="mt-2 list-disc space-y-0.5 pl-4 text-[11px] text-slate-500">
                    @foreach ($requirements as $requirement)
                        <li>{{ $requirement }}</li>
                    @endforeach
                </ul>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-admin.panel>
