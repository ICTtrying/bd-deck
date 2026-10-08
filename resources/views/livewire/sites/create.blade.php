<div class="grid max-w-3xl gap-6">
    <x-page-header :title="__('Site toevoegen')" :description="__('Koppel een bestaande WordPress- of Laravel-site bij WPMU DEV, Hostinger of elders, of begin met een nieuwe WordPress-site op je eigen computer en zet hem later live.')" :back="route('dashboard')">
        <x-slot:actions>
            @if ($kind === 'bestaand')
                <x-button icon="search" wire:click="import">{{ __('Bestaande verbindingen zoeken') }}</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('Soort site') }}">
        @foreach (['bestaand' => ['globe', __('Bestaande site koppelen'), __('De site staat al online. BD Deck haalt hem op en bouwt hem lokaal na.')], 'lokaal' => ['laptop', __('Nieuwe lokale site'), __('Een lege WordPress-site op je computer. Als hij af is, zet je hem met één knop live.')]] as $value => [$icon, $label, $text])
            <button type="button" wire:click="$set('kind', '{{ $value }}')" role="radio" aria-checked="{{ $kind === $value ? 'true' : 'false' }}" @class([
                'grid gap-1 rounded-xl border p-4 text-left transition-colors',
                'border-local bg-local-soft' => $kind === $value,
                'border-line bg-surface hover:border-line-strong' => $kind !== $value,
            ])>
                <span class="flex items-center gap-2 text-sm font-semibold"><x-icon :name="$icon" />{{ $label }}</span>
                <span class="text-[0.8125rem] text-muted">{{ $text }}</span>
            </button>
        @endforeach
    </div>

    @if ($kind === 'lokaal')
        <form wire:submit="createLocal" class="grid gap-6">
            <x-panel :title="__('Nieuwe lokale site')" :description="__('BD Deck maakt een DDEV-omgeving met de nieuwste WordPress (Nederlands), permalinks op berichtnaam en git voor je thema en plugins. Inloggen: dev / dev.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Naam')" for="local-name" error="localName" :hint="__('Wordt de map in je sites-map en het adres naam.ddev.site.')">
                        <x-input id="local-name" wire:model="localName" mono autocomplete="off" spellcheck="false" placeholder="klant-nieuw" />
                    </x-field>
                    <x-field :label="__('Titel van de website (optioneel)')" for="local-title" error="localTitle">
                        <x-input id="local-title" wire:model="localTitle" :placeholder="__('Bijvoorbeeld: Bakkerij De Korenaar')" />
                    </x-field>
                </div>
            </x-panel>
            <div class="flex items-center justify-end gap-2">
                <x-button :href="route('dashboard')" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
                <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Lokale site maken') }}</x-button>
            </div>
        </form>
    @else
        <form wire:submit="save" class="grid gap-6">
            @include('livewire.sites.partials.fields', ['creating' => true])

            <div class="flex items-center justify-end gap-2">
                <x-button :href="route('dashboard')" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
                <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Site toevoegen') }}</x-button>
            </div>
        </form>
    @endif
</div>
