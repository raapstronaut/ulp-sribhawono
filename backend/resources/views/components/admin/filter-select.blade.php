@props([
    'name',
    'label',
    // [value => label]
    'options' => [],
    'value' => '',
    'placeholder' => 'Semua',
    // false = wajib memilih salah satu opsi (tanpa opsi "Semua").
    'allowEmpty' => true,
    'disabled' => false,
])
<label class="flex flex-col gap-1">
    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ $label }}</span>
    <select name="{{ $name }}" @disabled($disabled) class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400">
        @if ($allowEmpty)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
</label>
