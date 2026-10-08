@props(['variant' => 'secondary', 'size' => 'md', 'icon' => null, 'href' => null, 'iconRight' => null])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium whitespace-nowrap transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none select-none';
    $sizes = [
        'sm' => 'h-8 px-2.5 text-[0.8125rem]',
        'md' => 'h-9 px-3.5 text-sm',
        'lg' => 'h-11 px-5 text-[0.9375rem]',
        'icon' => 'size-8',
    ];
    $variants = [
        'primary' => 'bg-local text-white hover:bg-local/90 dark:text-[#07131f]',
        'live' => 'bg-live text-[#04222c] hover:bg-live/85',
        'secondary' => 'bg-surface text-ink border border-line hover:border-line-strong hover:bg-raised',
        'ghost' => 'text-muted hover:text-ink hover:bg-raised',
        'danger' => 'bg-danger text-white hover:bg-danger/90',
        'danger-ghost' => 'text-danger hover:bg-danger-soft',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="$size === 'lg' ? 18 : 16" />@endif
        {{ $slot }}
        @if ($iconRight)<x-icon :name="$iconRight" :size="14" />@endif
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        @if ($icon && $attributes->has('wire:click'))
            <x-icon :name="$icon" :size="$size === 'lg' ? 18 : 16" wire:loading.remove wire:target="{{ $attributes->get('wire:click') }}" />
            <x-icon name="loader" :size="$size === 'lg' ? 18 : 16" class="animate-spin" wire:loading wire:target="{{ $attributes->get('wire:click') }}" />
        @elseif ($icon)
            <x-icon :name="$icon" :size="$size === 'lg' ? 18 : 16" />
        @endif
        {{ $slot }}
        @if ($iconRight)<x-icon :name="$iconRight" :size="14" />@endif
    </button>
@endif
