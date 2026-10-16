@props(['icon' => null, 'href' => null, 'danger' => false, 'description' => null])

@php
    $classes = 'flex w-full items-start gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-sm transition-colors disabled:pointer-events-none disabled:opacity-50 '
        .($danger ? 'text-danger hover:bg-danger-soft' : 'hover:bg-raised');
@endphp

@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="15" @class(['mt-0.5 shrink-0', 'text-faint' => ! $danger]) />@endif
        <span class="grid min-w-0"><span>{{ $slot }}</span>@if ($description)<span class="text-[0.8125rem] font-normal text-muted">{{ $description }}</span>@endif</span>
    </a>
@else
    <button type="button" role="menuitem" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="15" @class(['mt-0.5 shrink-0', 'text-faint' => ! $danger]) />@endif
        <span class="grid min-w-0"><span>{{ $slot }}</span>@if ($description)<span class="text-[0.8125rem] font-normal text-muted">{{ $description }}</span>@endif</span>
    </button>
@endif
