@props([
    'title' => 'Data tidak dapat dimuat',
    'description' => 'Terjadi kesalahan saat membaca database. Muat ulang halaman; bila berlanjut, periksa koneksi database atau status migration pada environment ini.',
    // inset = beri margin saat berada di dalam panel tanpa padding.
    'inset' => true,
])
<div {{ $attributes->class(['flex gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800', 'm-4' => $inset]) }} role="alert">
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
    <div>
        <h3 class="text-sm font-bold">{{ $title }}</h3>
        <p class="mt-0.5 text-xs leading-relaxed">{{ $description }}</p>
    </div>
</div>
