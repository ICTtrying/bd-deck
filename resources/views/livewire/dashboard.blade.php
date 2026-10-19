<div class="grid gap-7" wire:init="syncIfStale" wire:poll.60s="refreshSites">
    <x-page-header :title="__('Sites')" :description="trans_choice('{0} Nog geen sites gekoppeld.|{1} Eén site, lokaal en live naast elkaar.|[2,*] :count sites, lokaal en live naast elkaar.', $this->stats['total'])">
        <x-slot:actions>
            <x-dropdown width="w-72" class="inline-flex h-9 items-center gap-2 rounded-lg border border-line bg-surface px-3.5 text-sm font-medium text-ink transition-colors hover:border-line-strong hover:bg-raised" :label="__('Meer')">
                <x-slot:trigger><x-icon name="more" :size="16" />{{ __('Meer') }}</x-slot:trigger>
                <x-menu-item icon="download" x-on:click="$dispatch('open-site-export')" :description="__('Een bestand voor een collega, zonder wachtwoorden of sleutels.')">{{ __('Sitelijst exporteren…') }}</x-menu-item>
                <x-menu-item icon="upload" x-on:click="$dispatch('start-site-import')" :description="__('Sites overnemen uit het bestand van een collega.')">{{ __('Sitelijst importeren…') }}</x-menu-item>
            </x-dropdown>
            <x-button variant="primary" icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Site toevoegen') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->stats['total'] > 0)
        @php
            $visibleIds = $this->sites->pluck('id');
            $picked = $visibleIds->intersect($selected)->count();
            $selectState = match (true) {
                $picked === 0 => 'none',
                $picked === $visibleIds->count() => 'all',
                default => 'some',
            };
        @endphp
        <div class="flex flex-wrap items-center gap-3">
            {{-- eigen key per stand: zo krijgt een vers vakje de juiste half-aangevinkte stand --}}
            <label class="flex h-9 cursor-pointer items-center gap-2 pl-4 pr-1 text-[0.8125rem] text-muted hover:text-ink" wire:key="select-all-{{ $selectState }}">
                <input type="checkbox" wire:click="toggleSelectAll" @checked($selectState === 'all') @disabled($visibleIds->isEmpty()) @if ($selectState === 'some') x-data x-init="$el.indeterminate = true" @endif class="size-4 rounded border-line-strong accent-[var(--local)]">
                {{ __('Alles selecteren') }}
            </label>

            <div class="relative w-full max-w-xs">
                <x-icon name="search" :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
                <x-input type="search" wire:model.live.debounce.200ms="search" :placeholder="__('Zoek op naam of domein')" class="pl-8" :aria-label="__('Sites zoeken')" />
            </div>

            <div class="flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="{{ __('Filter') }}">
                @foreach (['all' => __('Alles'), 'favorites' => __('Favorieten'), 'pending' => __('Klaar voor live'), 'updates' => __('Updates')] as $value => $label)
                    @php $count = $value === 'pending' ? $this->stats['pending'] : ($value === 'updates' ? $this->stats['updates'] : 0); @endphp
                    <button type="button" wire:click="$set('scope', '{{ $value }}')" @class([
                        'h-7 rounded-md px-2.5 text-[0.8125rem] transition-colors',
                        'bg-local-soft font-medium text-local-ink' => $scope === $value,
                        'text-muted hover:text-ink' => $scope !== $value,
                    ]) aria-pressed="{{ $scope === $value ? 'true' : 'false' }}">
                        {{ $label }}
                        @if ($count > 0)
                            <span class="ml-1 rounded bg-live/20 px-1 text-2xs text-live-ink">{{ $count }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            @if ($this->providerCount > 1)
                <div class="w-40">
                    <x-select wire:model.live="provider" :aria-label="__('Provider')">
                        <option value="">{{ __('Alle providers') }}</option>
                        @foreach ($providers as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-select>
                </div>
            @endif

            <x-button size="icon" variant="ghost" icon="refresh" class="ml-auto" wire:click="refreshSites" :title="__('Status opnieuw ophalen')"><span class="sr-only">{{ __('Vernieuwen') }}</span></x-button>
        </div>
    @endif

    @if ($this->offlineSites->isNotEmpty())
        <div class="grid gap-1.5 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3" role="alert">
            @foreach ($this->offlineSites as $offline)
                <a href="{{ route('sites.show', ['site' => $offline, 'tab' => 'uptime']) }}" wire:navigate wire:key="offline-{{ $offline->id }}" class="flex items-center gap-2.5 text-sm text-danger hover:underline">
                    <x-icon name="alert" :size="14" />
                    <span class="font-medium">{{ __(':site is offline', ['site' => $offline->name]) }}</span>
                    @if ($offline->uptime_status_since)
                        <span class="opacity-80">{{ __('sinds :time', ['time' => $offline->uptime_status_since->diffForHumans()]) }}</span>
                    @endif
                    <span class="ml-auto text-xs opacity-70">{{ __('Bekijken') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->pendingSiteChanges->isNotEmpty())
        <div wire:poll.2s="pollSiteChanges" class="grid gap-1.5 rounded-xl border border-live/30 bg-live-soft px-4 py-3">
            @foreach ($this->pendingSiteChanges as $run)
                <a href="{{ route('activity.show', $run) }}" wire:navigate wire:key="pending-{{ $run->id }}" class="flex items-center gap-2.5 text-sm text-live-ink hover:underline">
                    <x-icon name="loader" :size="14" class="animate-spin" />
                    <span class="font-medium">{{ $run->label }}</span>
                    <span class="truncate opacity-80">{{ $run->site_name ?? implode(' ', array_slice($run->arguments, 1, 1)) }}</span>
                    <span class="ml-auto text-xs opacity-70">{{ __('Log bekijken') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->stats['total'] === 0)
        <x-empty-state icon="globe" :title="__('Koppel je eerste site')" :description="__('Voeg een site toe met de SSH- of SFTP-gegevens van WPMU DEV of Hostinger, begin met een nieuwe lokale site, of laat BD Deck zelf zoeken in je SSH-config, FileZilla en VS Code sftp.json. Nieuw met BD Deck? Loop eerst de stappen bij Aan de slag door.')">
            <div class="flex flex-wrap gap-2">
                <x-button variant="primary" icon="flag" :href="route('onboarding')" wire:navigate>{{ __('Aan de slag') }}</x-button>
                <x-button icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Site toevoegen') }}</x-button>
                <x-button icon="laptop" :href="route('sites.create', ['soort' => 'lokaal'])" wire:navigate>{{ __('Nieuwe lokale site') }}</x-button>
                <x-button icon="search" wire:click="import">{{ __('Bestaande verbindingen zoeken') }}</x-button>
                <x-button icon="upload" x-on:click="$dispatch('start-site-import')">{{ __('Sitelijst van een collega importeren') }}</x-button>
            </div>
        </x-empty-state>
    @elseif ($this->sites->isEmpty())
        <x-empty-state icon="search" :title="__('Niets gevonden')" :description="__('Geen site past bij deze zoekopdracht of dit filter.')">
            <x-button wire:click="$set('search', ''); $set('scope', 'all'); $set('provider', '')">{{ __('Filters wissen') }}</x-button>
        </x-empty-state>
    @else
        <x-panel :padding="false">
            <ul class="divide-y divide-line">
                @foreach ($this->sites as $site)
                    <x-site-row :site="$site" wire:key="site-{{ $site->id }}" />
                @endforeach
            </ul>
        </x-panel>
    @endif

    @if ($this->recentRuns->isNotEmpty())
        <x-panel :title="__('Recente activiteit')" :padding="false">
            <x-slot:actions>
                <x-button size="sm" variant="ghost" :href="route('activity.index')" wire:navigate iconRight="chevron-right">{{ __('Alles bekijken') }}</x-button>
            </x-slot:actions>
            <ul class="divide-y divide-line">
                @foreach ($this->recentRuns as $run)
                    <li wire:key="recent-{{ $run->id }}"><x-run-row :run="$run" /></li>
                @endforeach
            </ul>
        </x-panel>
    @endif

    @php $selectedCount = $this->selectedSites->count(); @endphp
    @if ($selectedCount > 0)
        @php $popover = 'absolute bottom-full left-0 z-30 mb-2 rounded-xl border border-line bg-surface p-1 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.4)]'; @endphp
        <div class="sticky bottom-4 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface px-4 py-2.5 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.45)]" role="region" aria-label="{{ __('Acties voor de geselecteerde sites') }}">
            <span class="mr-2 text-sm font-medium">{{ trans_choice(':count geselecteerd|:count geselecteerd', $selectedCount) }}</span>

            <x-button size="sm" icon="refresh" wire:click="bulkUpdates">{{ __('Updates controleren') }}</x-button>

            <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                <x-button size="sm" icon="zap" iconRight="chevron-down" x-on:click="open = ! open" x-bind:aria-expanded="open">{{ __('Cache legen') }}</x-button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity.duration.100ms class="{{ $popover }} w-64" role="menu">
                    <x-menu-item icon="laptop" wire:click="bulkCache('local')" x-on:click="open = false" :description="__('Alleen sites die lokaal gebouwd zijn.')">{{ __('Lokaal') }}</x-menu-item>
                    <x-menu-item icon="globe" wire:click="bulkCache('live')" x-on:click="open = false" :description="__('Op de live server; je bevestigt eerst welke sites.')">{{ __('Live') }}</x-menu-item>
                </div>
            </div>

            <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                <x-button size="sm" icon="archive" iconRight="chevron-down" x-on:click="open = ! open" x-bind:aria-expanded="open">{{ __('Back-up maken') }}</x-button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity.duration.100ms class="{{ $popover }} grid w-72 gap-1 p-3">
                    <p class="pb-1 text-[0.8125rem] font-medium">{{ __('Wat moet er in de back-up?') }}</p>
                    @foreach ([
                        'backupLocal' => [__('Lokale database en code'), __('Alleen bij sites die lokaal gebouwd zijn.')],
                        'backupLiveDatabase' => [__('Live database'), null],
                        'backupLiveFiles' => [__('Live bestanden'), __('Thema\'s en plugins, zonder uploads. Duurt langer.')],
                    ] as $part => [$label, $hint])
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg px-1.5 py-1 text-sm hover:bg-raised">
                            <input type="checkbox" wire:model="{{ $part }}" class="mt-0.5 size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                            <span class="grid">
                                <span>{{ $label }}</span>
                                @if ($hint)
                                    <span class="text-[0.8125rem] text-muted">{{ $hint }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                    <x-button size="sm" variant="primary" icon="archive" class="mt-1.5" wire:click="bulkBackup" x-on:click="open = false">{{ __('Back-up starten') }}</x-button>
                </div>
            </div>

            <x-button size="sm" variant="ghost" icon="x" class="ml-auto" wire:click="clearSelection">{{ __('Selectie wissen') }}</x-button>
        </div>
    @endif

    <x-modal name="bulk-live-cache" :title="__('Live cache legen')">
        <div class="grid gap-3 text-sm">
            <p class="text-muted">{{ __('De cache wordt geleegd op deze live sites. Bezoekers merken alleen dat de eerste pagina daarna iets langzamer laadt.') }}</p>
            <ul class="grid max-h-64 gap-1 overflow-y-auto">
                @foreach ($this->liveCacheSites as $cacheSite)
                    <li class="flex items-center gap-2" wire:key="live-cache-{{ $cacheSite->id }}">
                        <x-icon name="globe" :size="14" class="text-live-ink" />
                        <span class="font-medium">{{ $cacheSite->name }}</span>
                        <span class="truncate font-mono text-xs text-faint">{{ $cacheSite->data('live_host') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="live" icon="zap" wire:click="confirmLiveCache">{{ trans_choice('{0} Cache legen|{1} Cache legen op één site|[2,*] Cache legen op :count sites', $this->liveCacheSites->count()) }}</x-button>
        </x-slot:footer>
    </x-modal>

    <livewire:sites.delete-dialog />
    <livewire:site-list-transfer />
</div>
