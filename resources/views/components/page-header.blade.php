@props(['title', 'description' => null, 'back' => null])

<header {{ $attributes->merge(['class' => 'flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="grid gap-1">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="mb-1 inline-flex w-fit items-center gap-1 text-[0.8125rem] text-muted hover:text-ink">
                <x-icon name="arrow-left" :size="14" />{{ __('Terug') }}
            </a>
        @endif
        <h1 class="text-[1.375rem] font-semibold tracking-[-0.01em]">{{ $title }}</h1>
        @if ($description)
            <p class="max-w-[68ch] text-muted">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
