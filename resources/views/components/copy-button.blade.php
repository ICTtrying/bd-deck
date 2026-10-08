@props(['text', 'label' => null])

<button
    type="button"
    x-data="copyButton(@js($text))"
    x-on:click="copy"
    {{ $attributes->merge(['class' => 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 text-[0.8125rem] font-medium text-ink transition-colors hover:border-line-strong']) }}
>
    <span x-show="!copied" class="inline-flex items-center gap-1.5"><x-icon name="copy" :size="14" />{{ $label ?? __('Kopiëren') }}</span>
    <span x-show="copied" x-cloak class="inline-flex items-center gap-1.5 text-success"><x-icon name="check" :size="14" />{{ __('Gekopieerd') }}</span>
</button>
