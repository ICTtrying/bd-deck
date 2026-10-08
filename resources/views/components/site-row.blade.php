@props(['site'])

@php
    /** @var \App\Models\Site $site */
    $updates = $site->updatesCount();
    $pending = $site->pendingChanges();
    $liveHealth = collect($site->health ?? [])->firstWhere('key', 'http');
    $local = match (true) {
        ! $site->isBuilt() => ['label' => __('Nog niet gebouwd'), 'dot' => 'bg-faint'],
        $site->isRunning() => ['label' => __('Draait'), 'dot' => 'bg-success'],
        default => ['label' => __('Gestopt'), 'dot' => 'bg-faint'],
    };
    $live = match (true) {
        $site->isLocalOnly() => ['label' => __('Nog niet live'), 'dot' => 'bg-faint'],
        ($liveHealth['status'] ?? null) === 'ok' => ['label' => __('Online'), 'dot' => 'bg-success'],
        ($liveHealth['status'] ?? null) === 'fail' => ['label' => __('Niet bereikbaar'), 'dot' => 'bg-danger'],
        default => ['label' => __('Niet getest'), 'dot' => 'bg-faint'],
    };
    $menuItem = 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-sm hover:bg-raised';
@endphp

<li {{ $attributes->merge(['class' => 'group flex flex-wrap items-center gap-x-5 gap-y-2.5 px-4 py-3 transition-colors hover:bg-raised/50 lg:grid lg:grid-cols-[minmax(0,1fr)_auto_15.5rem]']) }}>
    <div class="flex min-w-0 flex-[1_1_16rem] items-center gap-2">
        <button type="button" wire:click="toggleFavorite({{ $site->id }})" @class([
            'grid size-7 shrink-0 place-items-center rounded-md transition-colors hover:bg-raised',
            'text-warning' => $site->is_favorite,
            'text-faint hover:text-muted' => ! $site->is_favorite,
        ]) aria-pressed="{{ $site->is_favorite ? 'true' : 'false' }}" title="{{ $site->is_favorite ? __('Uit favorieten') : __('Favoriet maken') }}">
            <x-icon name="star" :size="15" :class="$site->is_favorite ? 'fill-current' : ''" />
            <span class="sr-only">{{ __('Favoriet') }}</span>
        </button>
        <div class="grid min-w-0 gap-0.5">
            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="truncate text-[0.9375rem] font-semibold tracking-[-0.005em] hover:text-local-ink">{{ $site->name }}</a>
                @if ($site->isLocalOnly())
                    <x-badge tone="local">{{ __('Alleen lokaal') }}</x-badge>
                @else
                    @if ($site->isLaravel())
                        <x-badge tone="info">Laravel</x-badge>
                    @endif
                    <x-provider-badge :provider="$site->providerType" />
                    @unless ($site->mode->hasShell())
                        <x-badge>{{ __('SFTP') }}</x-badge>
                    @endunless
                @endif
                @if ($updates > 0)
                    <x-badge tone="warning">{{ trans_choice(':count update|:count updates', $updates) }}</x-badge>
                @endif
                @if ($site->healthTone() === 'danger')
                    <x-badge tone="danger">{{ __('Verbinding mislukt') }}</x-badge>
                @endif
            </div>
            <p class="truncate font-mono text-xs text-faint">{{ $site->isLocalOnly() ? $site->localUrl() : ($site->data('live_host') ?: $site->data('target')) }}</p>
        </div>
    </div>

    {{-- lokaal → wat klaarstaat → live, compact als één regel --}}
    <div class="flex flex-[0_1_auto] items-center gap-2 text-xs">
        <span class="inline-flex items-center gap-1.5 rounded-md bg-local-soft px-2 py-1 whitespace-nowrap text-local-ink lg:w-36">
            <x-icon name="laptop" :size="12" /><span class="size-1.5 rounded-full {{ $local['dot'] }}"></span>{{ $local['label'] }}
        </span>
        <span @class([
            'rounded-full border px-2 py-0.5 text-center text-2xs font-medium whitespace-nowrap lg:w-16',
            'border-live/40 text-live-ink' => $pending > 0,
            'border-line text-muted' => $pending === 0,
        ])>{{ $pending > 0 ? trans_choice(':count klaar|:count klaar', $pending) : ($site->isBuilt() && ! $site->isLocalOnly() ? __('Gelijk') : '—') }}</span>
        <span class="inline-flex items-center gap-1.5 rounded-md bg-live-soft px-2 py-1 whitespace-nowrap text-live-ink lg:w-32">
            <x-icon name="globe" :size="12" /><span class="size-1.5 rounded-full {{ $live['dot'] }}"></span>{{ $live['label'] }}
        </span>
    </div>

    <div class="ml-auto flex items-center justify-end gap-0.5">
        @if ($site->isBuilt())
            @if ($site->isLaravel())
                <x-quick-action :site="$site" target="local-site" icon="laptop" :label="__('Lokale site openen')" />
            @else
                <x-quick-action :site="$site" target="local-admin" icon="wordpress" :label="__('WP-admin lokaal')" />
            @endif
            <x-quick-action :site="$site" target="editor" icon="code" :label="__('Openen in editor')" />
            <x-quick-action :site="$site" target="terminal" icon="terminal" :label="__('Terminal in projectmap')" />
        @else
            <x-button size="sm" variant="primary" icon="hammer" wire:click="runQuick({{ $site->id }}, 'build')">{{ __('Lokaal bouwen') }}</x-button>
        @endif
        @if ($site->isLocalOnly())
            <x-button size="sm" variant="live" icon="rocket" class="ml-1" :href="route('sites.migrate', $site)" wire:navigate>{{ __('Live zetten') }}</x-button>
        @else
            <span class="mx-1 h-4 w-px bg-line"></span>
            <x-quick-action :site="$site" target="live-site" icon="globe" :label="__('Live website openen')" />
            @if ($site->isLaravel())
                <x-quick-action :site="$site" target="ssh" icon="server" :label="$site->mode->hasShell() ? __('SSH-sessie') : __('SFTP-sessie')" />
            @else
                <x-quick-action :site="$site" target="live-admin" icon="external" :label="__('WP-admin live')" />
            @endif
        @endif

        <div x-data="{ open: false }" class="relative">
            <button type="button" x-on:click="open = ! open" class="grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-raised hover:text-ink" title="{{ __('Meer') }}" aria-haspopup="menu" x-bind:aria-expanded="open">
                <x-icon name="more" /><span class="sr-only">{{ __('Meer') }}</span>
            </button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" x-transition.opacity.duration.100ms role="menu" class="absolute right-0 top-full z-20 mt-1 w-56 rounded-xl border border-line bg-surface p-1 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.4)]">
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="{{ $menuItem }}" role="menuitem"><x-icon name="grid" class="text-faint" />{{ __('Openen') }}</a>
                @unless ($site->isLocalOnly())
                    <a href="{{ route('sites.edit', $site) }}" wire:navigate class="{{ $menuItem }}" role="menuitem"><x-icon name="pencil" class="text-faint" />{{ __('Gegevens bewerken') }}</a>
                @endunless
                @unless ($site->isLaravel())
                    <a href="{{ route('sites.migrate', $site) }}" wire:navigate class="{{ $menuItem }}" role="menuitem"><x-icon name="rocket" class="text-faint" />{{ $site->isLocalOnly() ? __('Live zetten') : __('Verhuizen naar nieuwe server') }}</a>
                @endunless
                @if ($site->isBuilt())
                    <button type="button" class="{{ $menuItem }}" role="menuitem" wire:click="launch({{ $site->id }}, 'folder')" x-on:click="open = false"><x-icon name="folder" class="text-faint" />{{ __('Map openen') }}</button>
                @endif
                <div class="my-1 h-px bg-line"></div>
                <button type="button" class="{{ $menuItem }} text-danger hover:bg-danger-soft" role="menuitem" x-on:click="open = false; $dispatch('confirm-delete-site', { siteId: {{ $site->id }} })"><x-icon name="trash" />{{ __('Verwijderen') }}</button>
            </div>
        </div>
    </div>
</li>
