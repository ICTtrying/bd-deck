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

    <livewire:sites.delete-dialog />
</div>
