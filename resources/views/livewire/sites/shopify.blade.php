@php
    /** @var \App\Models\Site $site */
    $active = $this->activeRun;
    $git = $site->data('git');
    $tabs = [
        'overzicht' => __('Overzicht'),
        'instellingen' => __('Instellingen'),
        ...($site->hasLiveUrl() ? ['uptime' => __('Uptime')] : []),
        'logboek' => __('Logboek'),
    ];
@endphp

<div class="grid gap-6" x-data="shopifyShortcuts" x-on:keydown.window="handle($event)" @if ($active) wire:poll.1500ms="poll" @endif>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="grid gap-1.5">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex w-fit items-center gap-1 text-[0.8125rem] text-muted hover:text-ink">
                <x-icon name="arrow-left" :size="14" />{{ __('Alle sites') }}
            </a>
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-[1.5rem] font-semibold tracking-[-0.015em]">{{ $site->name }}</h1>
                <button type="button" wire:click="toggleFavorite({{ $site->id }})" @class(['grid size-8 place-items-center rounded-lg hover:bg-raised', 'text-warning' => $site->is_favorite, 'text-faint' => ! $site->is_favorite]) title="{{ __('Favoriet') }}">
                    <x-icon name="star" :class="$site->is_favorite ? 'fill-current' : ''" />
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-[0.8125rem] text-muted">
                <x-badge tone="info"><x-icon name="shopify" :size="11" />Shopify</x-badge>
                <span class="font-mono text-xs">{{ $site->shopifyStore() }}</span>
                @if ($site->isRunning())
                    <x-badge tone="success" dot>{{ __('Dev server draait') }}</x-badge>
                @endif
                @if ($site->isMonitored() && $site->uptime === \App\Enums\UptimeStatus::Up)
                    <button type="button" wire:click="$set('tab', 'uptime')" title="{{ __('Uptime bekijken') }}"><x-badge :tone="$site->uptime->tone()" dot>{{ $site->uptime->label() }}</x-badge></button>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-button icon="globe" wire:click="launch({{ $site->id }}, 'live-site')">{{ __('Live winkel') }}</x-button>
            <x-dropdown width="w-64" class="inline-flex h-9 items-center gap-2 rounded-lg border border-line bg-surface px-3.5 text-sm font-medium transition-colors hover:border-line-strong hover:bg-raised">
                <x-slot:trigger><x-icon name="more" />{{ __('Meer') }}</x-slot:trigger>
                <x-menu-item icon="shopify" wire:click="launch({{ $site->id }}, 'hosting')">{{ __('Shopify-admin openen') }}</x-menu-item>
                <x-menu-item icon="code" wire:click="launch({{ $site->id }}, 'editor')">{{ __('Openen in editor') }}</x-menu-item>
                <x-menu-item icon="terminal" wire:click="launch({{ $site->id }}, 'terminal')">{{ __('Terminal in projectmap') }}</x-menu-item>
                <x-menu-item icon="folder" wire:click="launch({{ $site->id }}, 'folder')">{{ __('Map openen') }}</x-menu-item>
                <x-menu-item icon="pencil" wire:click="$set('tab', 'instellingen')">{{ __('Instellingen') }}</x-menu-item>
                <div class="my-1 h-px bg-line"></div>
                <x-menu-item icon="trash" :danger="true" x-on:click="$dispatch('confirm-delete-site', { siteId: {{ $site->id }} })">{{ __('Project verwijderen') }}</x-menu-item>
            </x-dropdown>
        </div>
    </header>

    @if ($site->isOffline())
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3" role="alert">
            <x-icon name="alert" class="text-danger" />
            <p class="min-w-0 flex-1 text-sm"><span class="font-medium text-danger">{{ __(':site is offline', ['site' => $site->name]) }}</span> <span class="text-muted">{{ $site->uptime_status_since?->diffForHumans() ? __('sinds :time', ['time' => $site->uptime_status_since->diffForHumans()]) : '' }}</span></p>
            <x-button size="sm" wire:click="$set('tab', 'uptime')">{{ __('Uptime bekijken') }}</x-button>
        </div>
    @endif

    @unless ($site->isBuilt())
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3" role="alert">
            <x-icon name="alert" class="text-danger" />
            <p class="min-w-0 flex-1 text-sm"><span class="font-medium">{{ __('De projectmap is niet gevonden of is geen Shopify-thema.') }}</span> <span class="font-mono text-xs text-muted">{{ $site->projectDirectory() }}</span></p>
            <x-button size="sm" wire:click="$set('tab', 'instellingen')">{{ __('Pad aanpassen') }}</x-button>
        </div>
    @endunless

    @if ($active)
        <x-shopify-login :run="$active" />
        <x-run-progress :run="$active" />
    @elseif ($this->lastRun?->status === \App\Enums\RunStatus::Failed)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3">
            <x-icon name="alert" class="text-danger" />
            <p class="min-w-0 flex-1 text-sm"><span class="font-medium">{{ __(':action is mislukt.', ['action' => $this->lastRun->label]) }}</span> <span class="text-muted">{{ ltrim((string) $this->lastRun->lastMessage(), '✗ ') }}</span></p>
            <x-button size="sm" :href="route('activity.show', $this->lastRun)" wire:navigate>{{ __('Log bekijken') }}</x-button>
        </div>
    @endif

    <nav class="flex gap-1 border-b border-line" aria-label="{{ __('Onderdelen') }}">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                '-mb-px border-b-2 px-3 pb-2.5 text-sm transition-colors',
                'border-local font-medium text-ink' => $tab === $key,
                'border-transparent text-muted hover:text-ink' => $tab !== $key,
            ]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overzicht')
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
            <div class="grid content-start gap-5">
                <livewire:sites.shopify-dev :site="$site" :key="'shopify-dev-'.$site->id" />
                <livewire:sites.shopify-deploy :site="$site" :key="'shopify-deploy-'.$site->id" />
            </div>

            <div class="grid content-start gap-5">
                <x-panel :title="__('Versiebeheer')" :description="__('Elke push en publicatie legt de exacte staat vast als git-tag (bddeck/…); je branch en werkmap blijven zoals ze waren.')">
                    @if ($git)
                        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2.5 text-sm">
                            <dt class="text-muted">{{ __('Branch') }}</dt>
                            <dd class="flex items-center gap-1.5 font-mono text-[0.8125rem]"><x-icon name="git-branch" :size="14" class="text-faint" />{{ $git['branch'] ?: '–' }}</dd>
                            <dt class="text-muted">{{ __('Klaar voor live') }}</dt>
                            <dd>
                                @if (($git['pending_files'] ?? 0) + ($git['dirty'] ?? 0) === 0)
                                    {{ __('Niets, lokaal is gelijk aan live') }}
                                @else
                                    {{ trans_choice(':count gecommit bestand|:count gecommitte bestanden', $git['pending_files'] ?? 0) }}@if ($git['dirty'] ?? 0), <span class="text-warning">{{ trans_choice(':count nog niet gecommit|:count nog niet gecommit', $git['dirty']) }}</span>@endif
                                @endif
                            </dd>
                            <dt class="text-muted">{{ __('Laatste commit') }}</dt>
                            <dd class="min-w-0">
                                @if ($git['last_commit'] ?? null)
                                    <span class="truncate">{{ $git['last_commit']['subject'] }}</span>
                                    <span class="text-faint"> {{ \Illuminate\Support\Carbon::parse($git['last_commit']['date'])->diffForHumans() }}</span>
                                @else – @endif
                            </dd>
                            <dt class="text-muted">{{ __('Laatst live gezet') }}</dt>
                            <dd>{{ ($git['last_deploy'] ?? null) ? \Illuminate\Support\Carbon::parse($git['last_deploy'])->diffForHumans() : __('Nog niet via BD Deck') }}</dd>
                            <dt class="text-muted">{{ __('Back-ups') }}</dt>
                            <dd>{{ $site->data('backups', 0) }}@if ($site->lastBackupAt()) <span class="text-faint"> · {{ __('laatste :time', ['time' => $site->lastBackupAt()->diffForHumans()]) }}</span>@endif</dd>
                            <dt class="text-muted">{{ __('GitHub') }}</dt>
                            <dd class="truncate font-mono text-[0.8125rem]">{{ $git['remote'] ?? __('Geen remote') }}</dd>
                        </dl>
                    @else
                        <p class="text-muted">{{ __('De projectmap is geen git-repo of is niet gevonden.') }}</p>
                    @endif
                </x-panel>

                <x-panel :title="__('Notities')">
                    <x-textarea wire:model.blur="notes" wire:change="saveNotes" rows="4" :placeholder="__('Bijvoorbeeld: klant past de homepage zelf aan in de editor.')" />
                </x-panel>

                <x-panel :title="__('Verbinding')" :description="$site->health_checked_at ? __('Getest :time', ['time' => $site->health_checked_at->diffForHumans()]) : __('Nog niet getest')">
                    <x-slot:actions>
                        <x-button size="sm" icon="activity" wire:click="run('test')">{{ __('Testen') }}</x-button>
                    </x-slot:actions>
                    @if ($site->health)
                        <ul class="grid gap-2.5">
                            @foreach ($site->health as $check)
                                <li class="flex items-start gap-2.5">
                                    <span @class([
                                        'mt-1 grid size-4 shrink-0 place-items-center rounded-full',
                                        'bg-success-soft text-success' => $check['status'] === 'ok',
                                        'bg-danger-soft text-danger' => $check['status'] === 'fail',
                                        'bg-warning-soft text-warning' => $check['status'] === 'warn',
                                        'bg-raised text-faint' => $check['status'] === 'skip',
                                    ])>
                                        <x-icon :name="match ($check['status']) { 'ok' => 'check', 'fail' => 'x', 'warn' => 'alert', default => 'more' }" :size="10" />
                                    </span>
                                    <span class="grid leading-snug">
                                        <span class="text-sm font-medium">{{ $check['label'] }}</span>
                                        <span class="text-[0.8125rem] text-muted">{{ $check['detail'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">{{ __('Controleert of de Shopify CLI en de themamap in orde zijn, of je bent ingelogd en of de winkel bereikbaar is.') }}</p>
                    @endif
                </x-panel>

                <x-panel :title="__('Sneltoetsen')">
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[0.8125rem]">
                        @foreach ([['d', __('Dev server starten of stoppen')], ['p', __('Preview openen (start zo nodig)')], ['e', __('Theme editor openen')], ['s', __('Deelbare link openen')], ['c', __('Thema controleren')], ['l', __('Logboek')]] as [$key, $text])
                            <dt><kbd class="rounded border border-line bg-raised px-1.5 py-px font-mono text-xs">{{ $key }}</kbd></dt>
                            <dd class="text-muted">{{ $text }}</dd>
                        @endforeach
                    </dl>
                </x-panel>
            </div>
        </div>
    @elseif ($tab === 'instellingen')
        <livewire:sites.shopify-settings :site="$site" :key="'shopify-settings-'.$site->id" />
    @elseif ($tab === 'uptime' && $site->hasLiveUrl())
        <livewire:sites.uptime :site="$site" :key="'uptime-'.$site->id" />
    @else
        <x-panel :padding="false">
            @if ($this->runs->isEmpty())
                <div class="p-5"><x-empty-state icon="history" :title="__('Nog geen activiteit')" :description="__('Alles wat je met dit project doet, verschijnt hier met de volledige uitvoer.')" /></div>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->runs as $run)
                        <li wire:key="run-{{ $run->id }}"><x-run-row :run="$run" :show-site="false" /></li>
                    @endforeach
                </ul>
                <div class="border-t border-line px-5 py-3">{{ $this->runs->links() }}</div>
            @endif
        </x-panel>
    @endif

    <livewire:sites.delete-dialog />
</div>
