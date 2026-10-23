<div class="grid max-w-3xl gap-6">
    <x-page-header :title="__('Site toevoegen')" :description="__('Koppel een bestaande WordPress- of Laravel-site bij WPMU DEV, Hostinger of elders, begin met een nieuwe WordPress-site op je eigen computer, of koppel een Shopify-thema.')" :back="route('dashboard')">
        <x-slot:actions>
            @if ($kind === 'bestaand')
                <x-button icon="search" wire:click="import">{{ __('Bestaande verbindingen zoeken') }}</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('Soort site') }}">
        @foreach (['bestaand' => ['globe', __('Bestaande site koppelen'), __('De site staat al online. BD Deck haalt hem op en bouwt hem lokaal na.')], 'lokaal' => ['laptop', __('Nieuwe lokale site'), __('Een lege WordPress-site op je computer. Als hij af is, zet je hem met één knop live.')], 'shopify' => ['shopify', __('Shopify-thema'), __('Een themamap bij een winkel: dev server, preview en veilig live zetten.')]] as $value => [$icon, $label, $text])
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

    @if ($kind === 'shopify')
        <form wire:submit="createShopify" class="grid gap-6">
            <x-panel :title="__('Shopify-thema koppelen')" :description="__('Bestaat de map al, dan koppelt BD Deck hem. Bestaat hij nog niet, dan haalt BD Deck het live thema op, maakt er een git-repo van en legt het vast als eerste commit.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Naam')" for="shop-name" error="shopName" :hint="__('Meestal de klantnaam; ook de mapnaam.')">
                        <x-input id="shop-name" wire:model.live.debounce.300ms="shopName" mono autocomplete="off" spellcheck="false" placeholder="klantnaam" />
                    </x-field>
                    <x-field :label="__('Winkel (optioneel)')" for="shop-store" error="shopStore" :hint="__('Leeg laten: :store. Een andere winkel vul je in als myshopify.com-naam, niet als eigen domein.', ['store' => $this->shopifyStoreName])">
                        <x-input id="shop-store" wire:model.live.debounce.300ms="shopStore" mono autocomplete="off" spellcheck="false" :placeholder="$this->shopifyStoreName" />
                    </x-field>
                    <x-field :label="__('Map (optioneel)')" for="shop-path" error="shopPath" class="sm:col-span-2">
                        <x-input id="shop-path" wire:model.live.debounce.300ms="shopPath" mono autocomplete="off" :placeholder="$this->shopifyProjectPath" />
                    </x-field>
                    <x-field :label="__('Eigen domein (optioneel)')" for="shop-url" error="shopLiveUrl" class="sm:col-span-2">
                        <x-input id="shop-url" wire:model="shopLiveUrl" mono autocomplete="off" placeholder="https://klant.nl" />
                    </x-field>
                </div>
                <p class="mt-4 flex items-start gap-2 rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]">
                    <x-icon :name="$this->shopifyPathExists ? 'link' : 'download'" :size="15" class="mt-0.5 shrink-0 text-local-ink" />
                    <span>
                        <span class="font-mono text-xs">{{ $this->shopifyProjectPath }}</span><br>
                        {{ $this->shopifyPathExists ? __('Deze map bestaat al en wordt gekoppeld. De bestanden blijven zoals ze zijn.') : __('Deze map bestaat nog niet: het live thema van :store wordt opgehaald. De eerste keer vraagt Shopify om in te loggen; de link verschijnt dan bij de activiteit.', ['store' => $this->shopifyStoreName]) }}
                    </span>
                </p>
                <p class="mt-3 text-[0.8125rem] text-muted">{{ __('Heeft de winkel een wachtwoordpagina? Het storefront-wachtwoord sla je daarna op bij de instellingen van het project; het komt in de kluis.') }}</p>
            </x-panel>
            <div class="flex items-center justify-end gap-2">
                <x-button :href="route('dashboard')" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
                <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Project toevoegen') }}</x-button>
            </div>
        </form>
    @elseif ($kind === 'lokaal')
        <form wire:submit="createLocal" class="grid gap-6">
            <x-panel :title="__('Nieuwe lokale site')" :description="__('BD Deck maakt een DDEV-omgeving met de nieuwste WordPress (Nederlands), permalinks op berichtnaam en git voor je thema en plugins. Inloggen: dev / dev.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Naam')" for="local-name" error="localName" :hint="__('Adres: :host. De code erachter maakt het adres uniek, anders kan WPMU DEV het al aan iemand anders gekoppeld hebben.', ['host' => $this->localHost])">
                        <x-input id="local-name" wire:model.live.debounce.300ms="localName" mono autocomplete="off" spellcheck="false" placeholder="klant-nieuw" />
                    </x-field>
                    <x-field :label="__('Titel van de website (optioneel)')" for="local-title" error="localTitle">
                        <x-input id="local-title" wire:model="localTitle" :placeholder="__('Bijvoorbeeld: Bakkerij De Korenaar')" />
                    </x-field>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line px-4 py-3">
                    <span class="grid gap-0.5">
                        <span class="text-sm font-medium">{{ __('Plugins') }}</span>
                        <span class="text-[0.8125rem] text-muted">{{ trans_choice(':count gekozen|:count gekozen', count($premium) + count($plugins)) }}@if (trim($extraPlugins) !== ''){{ __(', plus eigen plugins') }}@endif</span>
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

            <x-modal name="local-packages" :title="__('Plugins kiezen')" width="max-w-2xl">
                <div class="grid gap-5">
                    @if ($this->premiumPackages->isNotEmpty())
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-[0.8125rem] font-medium">{{ __('Uit je premium-map') }}</legend>
                            <p class="-mt-1 mb-1 text-[0.8125rem] text-muted">{{ __('Pro-plugins download je als zip in de WPMU DEV Hub en zet je in Instellingen bij de zips; dan staan ze hier.') }}</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($this->premiumPackages as $key => $package)
                                    <label wire:key="premium-{{ $key }}" class="flex items-center gap-2.5 text-sm">
                                        <input type="checkbox" value="{{ $key }}" wire:model.live="premium" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                                        <span class="min-w-0 truncate" title="{{ $package['file'] }}">{{ $package['name'] ?? $package['slug'] }}</span>
                                        <x-badge>{{ $package['kind'] === 'theme' ? __('Thema') : __('Plugin') }}</x-badge>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-[0.8125rem] font-medium">{{ __('WPMU DEV-plugins, gratis versie') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($suggestedPlugins as $slug => $label)
                                <label wire:key="plugin-{{ $slug }}" class="flex items-center gap-2.5 text-sm">
                                    <input type="checkbox" value="{{ $slug }}" wire:model.live="plugins" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[0.8125rem] text-muted">{{ __('Van wordpress.org, dus zonder inloggen. De Pro-versies installeer je daarna via het WPMU DEV Dashboard, als je daar bent ingelogd.') }}</p>
                    </fieldset>

                    <x-field :label="__('Andere plugins van wordpress.org')" for="extra-plugins" error="extraPlugins" :hint="__('De naam uit de link, gescheiden door komma\'s. Bijvoorbeeld wordfence voor wordpress.org/plugins/wordfence.')">
                        <x-input id="extra-plugins" wire:model.blur="extraPlugins" mono placeholder="wordfence, redirection" autocomplete="off" spellcheck="false" />
                    </x-field>
                </div>
                <x-slot:footer>
                    <x-button wire:click="savePackages">{{ __('Opslaan voor elke nieuwe site') }}</x-button>
                    <x-button variant="primary" x-on:click="open = false">{{ __('Alleen voor deze site') }}</x-button>
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
