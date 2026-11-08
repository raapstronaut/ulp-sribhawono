@props([
    'name',
    'label',
    'value' => '',
    'type' => 'text',
    'placeholder' => null,
    'disabled' => false,
])
<label class="flex flex-col gap-1">
    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ $label }}</span>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ $value }}" @if ($placeholder) placeholder="{{ $placeholder }}" @endif @disabled($disabled) maxlength="100" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400">
</label>
