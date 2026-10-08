@props(['site', 'target', 'icon', 'label', 'chip' => false])

@php
    /** @var \App\Models\Site $site */
    $call = "launch({$site->id}, '{$target}')";
@endphp

{{-- inloglinks maken duurt een paar seconden: laat zien dat er iets gebeurt --}}
<button type="button" wire:click="{{ $call }}" wire:loading.attr="disabled" wire:target="{{ $call }}" title="{{ $label }}" {{ $attributes->class([
    'inline-flex h-7 items-center gap-1.5 rounded-md bg-surface/70 px-2 text-[0.8125rem] font-medium transition-colors hover:bg-surface disabled:opacity-60' => $chip,
    'grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-raised hover:text-ink disabled:opacity-60' => ! $chip,
]) }}>
    <x-icon :name="$icon" :size="$chip ? 14 : 16" wire:loading.remove wire:target="{{ $call }}" />
    <x-icon name="loader" :size="$chip ? 14 : 16" class="animate-spin" wire:loading wire:target="{{ $call }}" />
    @if ($chip)
        {{ $label }}
    @else
        <span class="sr-only">{{ $label }}</span>
    @endif
</button>
