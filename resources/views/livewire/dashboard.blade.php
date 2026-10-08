<div class="grid gap-7" wire:init="syncIfStale" wire:poll.60s="refreshSites">
    <x-page-header :title="__('Sites')" :description="trans_choice('{0} Nog geen sites gekoppeld.|{1} Eén site, lokaal en live naast elkaar.|[2,*] :count sites, lokaal en live naast elkaar.', $this->stats['total'])">
        <x-slot:actions>
            <x-button icon="refresh" wire:click="refreshSites" :title="__('Status opnieuw ophalen')">{{ __('Vernieuwen') }}</x-button>
            <x-button variant="primary" icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Site toevoegen') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->stats['total'] > 0)
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full max-w-xs">
                <x-icon name="search" :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
                <x-input type="search" wire:model.live.debounce.200ms="search" :placeholder="__('Zoek op naam of domein')" class="pl-8" :aria-label="__('Sites zoeken')" />
            </div>

            <div class="flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="{{ __('Filter') }}">
                @foreach (['all' => __('Alles'), 'favorites' => __('Favorieten'), 'built' => __('Lokaal gebouwd'), 'pending' => __('Klaar voor live')] as $value => $label)
                    <button type="button" wire:click="$set('scope', '{{ $value }}')" @class([
                        'h-7 rounded-md px-2.5 text-[0.8125rem] transition-colors',
                        'bg-local-soft font-medium text-local-ink' => $scope === $value,
                        'text-muted hover:text-ink' => $scope !== $value,
                    ]) aria-pressed="{{ $scope === $value ? 'true' : 'false' }}">
                        {{ $label }}
                        @if ($value === 'pending' && $this->stats['pending'] > 0)
                            <span class="ml-1 rounded bg-live/20 px-1 text-2xs text-live-ink">{{ $this->stats['pending'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="w-40">
                <x-select wire:model.live="provider" :aria-label="__('Provider')">
                    <option value="">{{ __('Alle providers') }}</option>
                    @foreach ($providers as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-select>
            </div>

            <p class="ml-auto text-[0.8125rem] text-faint">
                {{ trans_choice(':count lokaal gebouwd|:count lokaal gebouwd', $this->stats['built']) }}, {{ trans_choice(':count draait|:count draaien', $this->stats['running']) }}
            </p>
        </div>
    @endif

    @if ($this->stats['total'] === 0)
        <x-empty-state icon="globe" :title="__('Koppel je eerste site')" :description="__('Voeg een site toe met de SSH- of SFTP-gegevens van WPMU DEV of Hostinger. Of laat BD Deck zelf zoeken in je SSH-config, FileZilla en VS Code sftp.json.')">
            <x-button variant="primary" icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Site toevoegen') }}</x-button>
            <x-button icon="search" wire:click="import">{{ __('Bestaande verbindingen zoeken') }}</x-button>
        </x-empty-state>
    @elseif ($this->sites->isEmpty())
        <x-empty-state icon="search" :title="__('Niets gevonden')" :description="__('Geen site past bij deze zoekopdracht of dit filter.')">
            <x-button wire:click="$set('search', ''); $set('scope', 'all'); $set('provider', '')">{{ __('Filters wissen') }}</x-button>
        </x-empty-state>
    @else
        <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))]">
            @foreach ($this->sites as $site)
                <x-site-card :site="$site" wire:key="site-{{ $site->id }}" />
            @endforeach
        </div>
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
</div>
