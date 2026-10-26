@props([
    // Lebar minimum agar tabel bergulir horizontal di layar sempit.
    'minWidth' => 'min-w-[800px]',
])
<div class="overflow-x-auto">
    <table {{ $attributes->class(['w-full text-left text-sm text-slate-600', $minWidth]) }}>
        <thead class="border-b border-slate-200 bg-white text-[10px] font-bold uppercase tracking-wider text-slate-500">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            {{ $slot }}
        </tbody>
    </table>
</div>
