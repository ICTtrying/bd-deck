@php
    $primary = [
        ['route' => 'dashboard', 'match' => ['dashboard', 'sites.*'], 'icon' => 'grid', 'label' => __('Sites')],
        ['route' => 'maintenance.index', 'match' => ['maintenance.*'], 'icon' => 'activity', 'label' => __('Uptime & onderhoud')],
        ['route' => 'activity.index', 'match' => ['activity.*'], 'icon' => 'history', 'label' => __('Activiteit')],
    ];
    $manage = [
        ['route' => 'keys.index', 'match' => ['keys.*'], 'icon' => 'key', 'label' => __('SSH-sleutels')],
        ['route' => 'vault.index', 'match' => ['vault.*'], 'icon' => 'shield', 'label' => __('Kluis')],
        ['route' => 'settings.index', 'match' => ['settings.*'], 'icon' => 'settings', 'label' => __('Instellingen')],
    ];
    $attention = $this->attention;
    $link = fn (bool $active): string => $active
        ? 'flex h-8 items-center gap-2.5 rounded-lg bg-local-soft px-2.5 text-sm font-medium text-local-ink transition-colors'
        : 'flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-sm text-muted transition-colors hover:bg-raised hover:text-ink';
@endphp

<aside class="flex h-full w-60 shrink-0 flex-col border-r border-line bg-surface" @if ($this->activeRuns->isNotEmpty()) wire:poll.3s @else wire:poll.30s @endif>
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
        @foreach ($primary as $item)
            @php $active = request()->routeIs(...$item['match']); @endphp
            <a href="{{ route($item['route']) }}" wire:navigate class="{{ $link($active) }}" @if ($active) aria-current="page" @endif>
                <x-icon :name="$item['icon']" />
                <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                @if ($item['route'] === 'maintenance.index' && ($attention['offline'] > 0 || $attention['warnings'] > 0))
                    <span @class([
                        'grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-2xs font-semibold',
                        'bg-danger text-white' => $attention['offline'] > 0,
                        'bg-warning-soft text-warning' => $attention['offline'] === 0,
                    ]) title="{{ $attention['offline'] > 0 ? trans_choice(':count site offline|:count sites offline', $attention['offline']) : trans_choice(':count site heeft aandacht nodig|:count sites hebben aandacht nodig', $attention['warnings']) }}">{{ $attention['offline'] > 0 ? $attention['offline'] : $attention['warnings'] }}</span>
                @endif
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
                    <span @class(['size-1.5 shrink-0 rounded-full', 'bg-danger' => $favorite->isOffline(), 'bg-success' => $favorite->isMonitored() && $favorite->uptime === \App\Enums\UptimeStatus::Up, 'bg-line-strong' => ! $favorite->isOffline() && ! ($favorite->isMonitored() && $favorite->uptime === \App\Enums\UptimeStatus::Up)]) title="{{ $favorite->isMonitored() ? $favorite->uptime->label() : '' }}"></span>
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

        <nav class="grid gap-px border-t border-line pt-2" aria-label="{{ __('Beheer') }}">
            @foreach ($manage as $item)
                @php $active = request()->routeIs(...$item['match']); @endphp
                <a href="{{ route($item['route']) }}" wire:navigate class="{{ $link($active) }}" @if ($active) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" />{{ $item['label'] }}
                </a>
            @endforeach
            <a href="{{ route('onboarding') }}" wire:navigate class="{{ $link(request()->routeIs('onboarding')) }}">
                <x-icon name="flag" />{{ __('Aan de slag') }}
            </a>
            <button type="button" onclick="document.getElementById('lock-form').submit()" class="{{ $link(false) }} w-full">
                <x-icon name="lock" />{{ __('Vergrendelen') }}
            </button>
        </nav>
    </div>
</aside>
