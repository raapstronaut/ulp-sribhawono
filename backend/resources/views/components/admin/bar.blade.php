@props([
    'value',
    'max',
    'label' => null,
])
@php
    $percent = $max > 0 ? max(2, min(100, (int) round($value / $max * 100))) : 0;
@endphp
{{-- Bar proporsional satu warna; nilai angka selalu ditulis di sel tabel di sebelahnya. --}}
<div class="h-1.5 w-full min-w-16 overflow-hidden rounded-full bg-slate-100" role="presentation" title="{{ $label ?? number_format((float) $value, 0, ',', '.') }}">
    @if ($value > 0)
        <div class="h-1.5 rounded-full bg-blue-500" style="width: {{ $percent }}%"></div>
    @endif
</div>
