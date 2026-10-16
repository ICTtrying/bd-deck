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
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line px-4 py-3">
                    <span class="grid gap-0.5">
                        <span class="text-sm font-medium">{{ __('Plugins en thema\'s') }}</span>
                        <span class="text-[0.8125rem] text-muted">{{ trans_choice(':count gekozen|:count gekozen', count($premium) + count($plugins) + count($themes)) }}@if (trim($extraPlugins) !== ''){{ __(', plus eigen plugins') }}@endif</span>
                    </span>
                    <x-button size="sm" icon="list" x-on:click="$dispatch('open-modal', 'local-packages')">{{ __('Kiezen') }}</x-button>
                </div>
                <div class="mt-4">
                    <x-toggle wire:model="localMultisite" :label="__('Als multisite')" :description="__('Meerdere sites met elk een eigen adres in één WordPress. Subsites voeg je daarna toe op de sitepagina. Een multisite kan BD Deck niet live zetten.')" />
                </div>
                <p class="mt-4 text-[0.8125rem] text-muted">{{ __('Enfold en de WPMU DEV-plugins worden automatisch geïnstalleerd en geactiveerd.') }} <a href="{{ route('settings.index') }}#premium" wire:navigate class="font-medium text-local-ink hover:underline">{{ __('Zips beheren in Instellingen') }}</a></p>
            </x-panel>
            <div class="flex items-center justify-end gap-2">
                <x-button :href="route('dashboard')" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
                <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Lokale site maken') }}</x-button>
            </div>

            <x-modal name="local-packages" :title="__('Plugins en thema\'s kiezen')" width="max-w-2xl">
                <div class="grid gap-5">
                    @if ($this->premiumPackages->isNotEmpty())
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-[0.8125rem] font-medium">{{ __('Uit je premium-map') }}</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($this->premiumPackages as $key => $package)
                                    <label wire:key="premium-{{ $key }}" class="flex items-center gap-2.5 text-sm">
                                        <input type="checkbox" value="{{ $key }}" wire:model.live="premium" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                                        <span class="min-w-0 truncate">{{ $package['slug'] }}</span>
                                        <x-badge>{{ $package['kind'] === 'theme' ? __('Thema') : __('Plugin') }}</x-badge>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-[0.8125rem] font-medium">{{ __('Gratis plugins van wordpress.org') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($suggestedPlugins as $slug => $label)
                                <label wire:key="plugin-{{ $slug }}" class="flex items-center gap-2.5 text-sm">
                                    <input type="checkbox" value="{{ $slug }}" wire:model.live="plugins" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-field :label="__('Andere plugins van wordpress.org')" for="extra-plugins" error="extraPlugins" :hint="__('De naam uit de link, gescheiden door komma\'s. Bijvoorbeeld wordfence voor wordpress.org/plugins/wordfence.')">
                        <x-input id="extra-plugins" wire:model.blur="extraPlugins" mono placeholder="wordfence, redirection" autocomplete="off" spellcheck="false" />
                    </x-field>

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-[0.8125rem] font-medium">{{ __('Gratis thema\'s van wordpress.org') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($suggestedThemes as $slug => $label)
                                <label wire:key="theme-{{ $slug }}" class="flex items-center gap-2.5 text-sm">
                                    <input type="checkbox" value="{{ $slug }}" wire:model.live="themes" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[0.8125rem] text-muted">{{ __('Kies je ook een premium-thema zoals Enfold, dan blijft dat het actieve thema.') }}</p>
                    </fieldset>
                </div>
                <x-slot:footer>
                    <x-button variant="primary" x-on:click="open = false">{{ __('Klaar') }}</x-button>
                </x-slot:footer>
            </x-modal>
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
