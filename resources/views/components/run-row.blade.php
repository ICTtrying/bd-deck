@props(['run', 'showSite' => true])

<a href="{{ route('activity.show', $run) }}" wire:navigate {{ $attributes->merge(['class' => 'flex items-center gap-3 px-5 py-2.5 transition-colors hover:bg-raised']) }}>
    <span @class([
        'grid size-7 shrink-0 place-items-center rounded-lg',
        'bg-success-soft text-success' => $run->status->tone() === 'success',
        'bg-danger-soft text-danger' => $run->status->tone() === 'danger',
        'bg-live-soft text-live-ink' => $run->status->tone() === 'info',
        'bg-raised text-muted' => in_array($run->status->tone(), ['neutral', 'warning'], true),
    ])>
        @if ($run->status->isActive())
            <x-icon name="loader" :size="14" class="animate-spin" />
        @else
            <x-icon :name="$run->action->icon()" :size="14" />
        @endif
    </span>
    <span class="grid min-w-0 flex-1 leading-tight">
        <span class="truncate text-sm font-medium">{{ $run->label }}@if ($showSite && $run->site_name)<span class="font-normal text-muted"> {{ __('op :site', ['site' => $run->site_name]) }}</span>@endif</span>
        <span class="truncate text-[0.8125rem] text-muted">{{ $run->lastMessage() ?? $run->status->label() }}</span>
    </span>
    <span class="shrink-0 text-right text-[0.8125rem] text-faint">
        <x-badge :tone="$run->status->tone()">{{ $run->status->label() }}</x-badge>
        <span class="mt-0.5 block">{{ $run->created_at->diffForHumans(short: true) }}</span>
    </span>
</a>
