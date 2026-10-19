@props(['site'])

@php
    /** @var \App\Models\Site $site */
    $updates = $site->updatesCount();
    $pending = $site->pendingChanges();
    $liveHealth = collect($site->health ?? [])->firstWhere('key', 'http');
    $monitored = $site->isMonitored();
    $local = match (true) {
        ! $site->isBuilt() => ['label' => __('Nog niet gebouwd'), 'dot' => 'bg-faint'],
        $site->isRunning() => ['label' => __('Draait'), 'dot' => 'bg-success'],
        default => ['label' => __('Gestopt'), 'dot' => 'bg-faint'],
    };
    $live = match (true) {
        $site->isLocalOnly() => ['label' => __('Nog niet live'), 'dot' => 'bg-faint'],
        $site->isOffline() => ['label' => __('Offline'), 'dot' => 'bg-danger animate-pulse'],
        $monitored && $site->uptime === \App\Enums\UptimeStatus::Up => ['label' => __('Online'), 'dot' => 'bg-success'],
        ($liveHealth['status'] ?? null) === 'ok' => ['label' => __('Online'), 'dot' => 'bg-success'],
        ($liveHealth['status'] ?? null) === 'fail' => ['label' => __('Niet bereikbaar'), 'dot' => 'bg-danger'],
        default => ['label' => __('Niet getest'), 'dot' => 'bg-faint'],
    };
    $chip = 'inline-flex h-8 w-full items-center gap-1.5 rounded-lg px-2.5 text-xs whitespace-nowrap transition-shadow hover:ring-1';
    $opening = "\$dispatch('toast', { title: '".__('Wordt geopend…')."', tone: 'info' })";
@endphp

<li {{ $attributes->merge(['class' => 'group flex flex-wrap items-center gap-x-4 gap-y-2.5 px-4 py-3 transition-colors hover:bg-raised/50 xl:grid xl:grid-cols-[minmax(0,1fr)_auto_11.5rem]']) }}>
    {{-- wie --}}
    <div class="flex min-w-0 flex-[1_1_100%] items-center gap-2 xl:flex-[1_1_16rem]">
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
                    <a href="{{ route('sites.show', $site) }}" wire:navigate><x-badge tone="warning">{{ trans_choice(':count update|:count updates', $updates) }}</x-badge></a>
                @endif
                @if ($site->isShared())
                    <a href="{{ route('sites.show', $site) }}" wire:navigate title="{{ $site->shareUrl() }}"><x-badge tone="live">{{ __('Gedeeld') }}</x-badge></a>
                @endif
                @if ($site->healthTone() === 'danger')
                    <x-badge tone="danger">{{ __('Verbinding mislukt') }}</x-badge>
                @endif
            </div>
            <p class="truncate font-mono text-xs text-faint">{{ $site->isLocalOnly() ? $site->localUrl() : ($site->data('live_host') ?: $site->data('target')) }}</p>
        </div>
    </div>

    {{-- lokaal → wat klaarstaat → live: de blokken zijn zelf de knoppen --}}
    <div class="flex flex-[0_1_auto] items-center gap-2">
        <div class="w-44">
            <x-dropdown align="left" width="w-64" :class="$chip.' bg-local-soft text-local-ink hover:ring-local/30'" :label="__('Acties voor de lokale site')">
                <x-slot:trigger>
                    <x-icon name="laptop" :size="13" /><span class="size-1.5 shrink-0 rounded-full {{ $local['dot'] }}"></span><span class="truncate">{{ $local['label'] }}</span><x-icon name="chevron-down" :size="12" class="ml-auto opacity-60" />
                </x-slot:trigger>
                @if ($site->isBuilt())
                    @php $startHint = $site->isRunning() ? null : __('Start eerst de lokale site (±15 seconden).'); @endphp
                    @unless ($site->isLaravel())
                        <x-menu-item icon="wordpress" wire:click="launch({{ $site->id }}, 'local-admin')" :description="$startHint">{{ __('WP-admin lokaal') }}</x-menu-item>
                    @endunless
                    <x-menu-item icon="globe" wire:click="launch({{ $site->id }}, 'local-site')" :description="$startHint">{{ __('Lokale site openen') }}</x-menu-item>
                    <x-menu-item icon="code" wire:click="launch({{ $site->id }}, 'editor')">{{ __('Openen in editor') }}</x-menu-item>
                    <x-menu-item icon="terminal" wire:click="launch({{ $site->id }}, 'terminal')">{{ __('Terminal in projectmap') }}</x-menu-item>
                    <x-menu-item icon="folder" wire:click="launch({{ $site->id }}, 'folder')">{{ __('Map openen') }}</x-menu-item>
                    <x-menu-item icon="mail" wire:click="launch({{ $site->id }}, 'mailpit')" :description="__('Mail van de lokale site komt hier binnen; er gaat niets echt de deur uit.')">{{ __('Mailpit (testmails)') }}</x-menu-item>
                    <div class="my-1 h-px bg-line"></div>
                    <x-menu-item :icon="$site->isRunning() ? 'square' : 'play'" wire:click="runQuick({{ $site->id }}, '{{ $site->isRunning() ? 'stop' : 'start' }}')">{{ $site->isRunning() ? __('Lokale site stoppen') : __('Lokale site starten') }}</x-menu-item>
                @else
                    <x-menu-item icon="hammer" wire:click="runQuick({{ $site->id }}, 'build')" :description="__('Haalt code en database van live en zet er een lokale kopie van op.')">{{ __('Lokaal bouwen') }}</x-menu-item>
                @endif
            </x-dropdown>
        </div>

        <span @class([
            'rounded-full border px-2 py-0.5 text-center text-2xs font-medium whitespace-nowrap xl:w-14',
            'border-live/40 text-live-ink' => $pending > 0,
            'border-line text-muted' => $pending === 0,
        ]) title="{{ $pending > 0 ? __('Wijzigingen die lokaal klaarstaan om live te zetten') : __('Lokaal en live zijn gelijk') }}">{{ $pending > 0 ? trans_choice(':count klaar|:count klaar', $pending) : ($site->isBuilt() && ! $site->isLocalOnly() ? __('Gelijk') : '—') }}</span>

        <div class="w-32">
            @if ($site->isLocalOnly())
                <span class="{{ $chip }} bg-live-soft text-live-ink"><x-icon name="globe" :size="13" /><span class="size-1.5 shrink-0 rounded-full {{ $live['dot'] }}"></span>{{ $live['label'] }}</span>
            @else
                <x-dropdown align="right" width="w-64" :class="$chip.' bg-live-soft text-live-ink hover:ring-live/40'" :label="__('Acties voor de live site')">
                    <x-slot:trigger>
                        <x-icon name="globe" :size="13" /><span class="size-1.5 shrink-0 rounded-full {{ $live['dot'] }}"></span><span class="truncate">{{ $live['label'] }}</span><x-icon name="chevron-down" :size="12" class="ml-auto opacity-60" />
                    </x-slot:trigger>
                    <x-menu-item icon="globe" wire:click="launch({{ $site->id }}, 'live-site')">{{ __('Live website openen') }}</x-menu-item>
                    @unless ($site->isLaravel())
                        <x-menu-item icon="wordpress" wire:click="launch({{ $site->id }}, 'live-admin')" x-on:click="{{ $opening }}">{{ __('WP-admin live') }}</x-menu-item>
                    @endunless
                    <x-menu-item icon="server" wire:click="launch({{ $site->id }}, 'ssh')">{{ $site->mode->hasShell() ? __('SSH-sessie') : __('SFTP-sessie') }}</x-menu-item>
                    @if ($site->providerType->dashboardUrl())
                        <x-menu-item icon="external" wire:click="launch({{ $site->id }}, 'hosting')">{{ __(':provider openen', ['provider' => $site->providerType->label()]) }}</x-menu-item>
                    @endif
                    <div class="my-1 h-px bg-line"></div>
                    <x-menu-item icon="activity" :href="route('sites.show', ['site' => $site, 'tab' => 'uptime'])" wire:navigate>{{ __('Uptime bekijken') }}</x-menu-item>
                </x-dropdown>
            @endif
        </div>
    </div>

    {{-- wat nu het logische vervolg is, plus de zeldzame dingen --}}
    <div class="ml-auto flex items-center justify-end gap-1.5">
        @if ($site->isLocalOnly())
            <x-button size="sm" variant="live" icon="rocket" :href="route('sites.migrate', $site)" wire:navigate>{{ __('Live zetten') }}</x-button>
        @elseif (! $site->isBuilt())
            <x-button size="sm" variant="primary" icon="hammer" wire:click="runQuick({{ $site->id }}, 'build')">{{ __('Lokaal bouwen') }}</x-button>
        @else
            <x-button size="sm" :href="route('sites.show', $site)" wire:navigate>{{ __('Openen') }}</x-button>
        @endif

        <x-dropdown width="w-56" class="grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-raised hover:text-ink" :label="__('Meer')">
            <x-slot:trigger><x-icon name="more" /><span class="sr-only">{{ __('Meer') }}</span></x-slot:trigger>
            <x-menu-item icon="grid" :href="route('sites.show', $site)" wire:navigate>{{ __('Site openen') }}</x-menu-item>
            @unless ($site->isLocalOnly())
                <x-menu-item icon="pencil" :href="route('sites.edit', $site)" wire:navigate>{{ __('Gegevens bewerken') }}</x-menu-item>
            @endunless
            @unless ($site->isLaravel())
                <x-menu-item icon="rocket" :href="route('sites.migrate', $site)" wire:navigate>{{ $site->isLocalOnly() ? __('Live zetten') : __('Verhuizen naar nieuwe server') }}</x-menu-item>
            @endunless
            <div class="my-1 h-px bg-line"></div>
            <x-menu-item icon="trash" :danger="true" x-on:click="$dispatch('confirm-delete-site', { siteId: {{ $site->id }} })">{{ __('Verwijderen') }}</x-menu-item>
        </x-dropdown>
    </div>
</li>
