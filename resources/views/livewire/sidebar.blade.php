@php
    $nav = [
        ['route' => 'dashboard', 'match' => ['dashboard', 'sites.*'], 'icon' => 'grid', 'label' => __('Sites')],
        ['route' => 'activity.index', 'match' => ['activity.*'], 'icon' => 'history', 'label' => __('Activiteit')],
        ['route' => 'keys.index', 'match' => ['keys.*'], 'icon' => 'key', 'label' => __('SSH-sleutels')],
        ['route' => 'vault.index', 'match' => ['vault.*'], 'icon' => 'shield', 'label' => __('Kluis')],
        ['route' => 'settings.index', 'match' => ['settings.*'], 'icon' => 'settings', 'label' => __('Instellingen')],
        ['route' => 'onboarding', 'match' => ['onboarding'], 'icon' => 'flag', 'label' => __('Aan de slag')],
    ];
@endphp

<aside class="flex h-full w-60 shrink-0 flex-col border-r border-line bg-surface" @if ($this->activeRuns->isNotEmpty()) wire:poll.3s @endif>
    <div class="flex items-center gap-2.5 px-4 pt-4 pb-3">
        <img src="{{ asset('images/bd-mark.png') }}" alt="" class="size-7 dark:hidden"><img src="{{ asset('icon.png') }}" alt="" class="size-7 hidden dark:block">
        <div class="leading-tight">
            <p class="text-[0.9375rem] font-semibold tracking-[-0.01em]">BD Deck</p>
            <p class="text-2xs text-faint">Borgman Digital</p>
        </div>
    </div>

    <div class="px-3 pb-2">
        <button type="button" x-on:click="$dispatch('open-palette')" class="flex h-8 w-full items-center gap-2 rounded-lg border border-line bg-raised px-2.5 text-[0.8125rem] text-faint transition-colors hover:border-line-strong hover:text-muted">
            <x-icon name="search" :size="14" />
            <span class="flex-1 text-left">{{ __('Zoeken…') }}</span>
            <kbd class="rounded border border-line bg-surface px-1 font-sans text-2xs">Ctrl K</kbd>
        </button>
    </div>

    <nav class="grid gap-px px-3 py-1" aria-label="{{ __('Hoofdmenu') }}">
        @foreach ($nav as $item)
            @php $active = request()->routeIs(...$item['match']); @endphp
            <a href="{{ route($item['route']) }}" wire:navigate @class([
                'flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-sm transition-colors',
                'bg-local-soft font-medium text-local-ink' => $active,
                'text-muted hover:bg-raised hover:text-ink' => ! $active,
            ]) @if ($active) aria-current="page" @endif>
                <x-icon :name="$item['icon']" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    @if ($this->favorites->isNotEmpty())
        <div class="mt-4 grid gap-px px-3">
            <p class="px-2.5 pb-1 text-xs font-medium text-faint">{{ __('Favorieten') }}</p>
            @foreach ($this->favorites as $favorite)
                <a href="{{ route('sites.show', $favorite) }}" wire:navigate wire:key="fav-{{ $favorite->id }}" @class([
                    'flex h-7 items-center gap-2.5 rounded-lg px-2.5 text-[0.8125rem] transition-colors',
                    'bg-raised text-ink' => request()->route('site')?->is($favorite),
                    'text-muted hover:bg-raised hover:text-ink' => ! request()->route('site')?->is($favorite),
                ])>
                    <span @class(['size-1.5 rounded-full', 'bg-success' => $favorite->isRunning(), 'bg-line-strong' => ! $favorite->isRunning()])></span>
                    <span class="truncate">{{ $favorite->name }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="mt-auto grid gap-2 p-3">
        @if ($this->activeRuns->isNotEmpty())
            <div class="grid gap-1 rounded-lg border border-live/30 bg-live-soft p-2">
                @foreach ($this->activeRuns as $run)
                    <a href="{{ route('activity.show', $run) }}" wire:navigate wire:key="active-{{ $run->id }}" class="flex items-center gap-2 rounded-md px-1 py-0.5 text-xs text-live-ink hover:bg-surface/60">
                        <x-icon name="loader" :size="12" class="animate-spin" />
                        <span class="grid min-w-0 leading-tight"><span class="truncate font-medium">{{ $run->label }}</span>@if ($run->site_name)<span class="truncate opacity-75">{{ $run->site_name }}</span>@endif</span>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="flex items-center justify-end gap-2 px-1">
            <button type="button" onclick="document.getElementById('lock-form').submit()" class="inline-flex h-7 items-center gap-1.5 rounded-lg px-2 text-[0.8125rem] text-muted transition-colors hover:bg-raised hover:text-ink">
                <x-icon name="lock" :size="14" />
                {{ __('Vergrendelen') }}
            </button>
        </div>
    </div>
</aside>
