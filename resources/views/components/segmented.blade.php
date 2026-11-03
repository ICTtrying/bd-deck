@props(['options', 'property', 'value', 'label'])

<div class="flex rounded-lg border border-line bg-raised p-0.5" role="radiogroup" aria-label="{{ $label }}">
    @foreach ($options as $optionValue => $optionLabel)
        <button type="button" wire:click="$set('{{ $property }}', '{{ $optionValue }}')" role="radio" aria-checked="{{ (string) $value === (string) $optionValue ? 'true' : 'false' }}" @class([
            'flex h-8 flex-1 items-center justify-center rounded-md px-2 text-[0.8125rem] transition-colors',
            'bg-surface font-medium text-ink shadow-sm' => (string) $value === (string) $optionValue,
            'text-muted hover:text-ink' => (string) $value !== (string) $optionValue,
        ])>{{ $optionLabel }}</button>
    @endforeach
</div>
