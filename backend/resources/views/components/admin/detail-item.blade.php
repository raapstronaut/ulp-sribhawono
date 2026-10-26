@props([
    'label',
])
<div {{ $attributes }}>
    <dt class="text-xs text-slate-500">{{ $label }}</dt>
    <dd class="break-words font-semibold text-slate-900">@if ($slot->isEmpty())-@else{{ $slot }}@endif</dd>
</div>
