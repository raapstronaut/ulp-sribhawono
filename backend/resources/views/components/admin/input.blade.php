@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
])
@php
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($errorKey);
    $current = $type === 'password' || $type === 'file' ? null : old($errorKey, $value);
@endphp
<label class="flex flex-col gap-1">
    <span class="text-xs font-semibold text-slate-700">{{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif</span>
    <input type="{{ $type }}" name="{{ $name }}" @if ($current !== null) value="{{ $current }}" @endif @required($required)
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class(['w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100', 'border-red-400' => $hasError, 'border-slate-300' => ! $hasError]) }}>
    @if ($hasError)
        <span class="text-[11px] text-red-600">{{ $errors->first($errorKey) }}</span>
    @elseif ($hint)
        <span class="text-[11px] text-slate-500">{{ $hint }}</span>
    @endif
</label>
