<div class="grid max-w-3xl gap-5">
    <x-panel :title="__('Winkel en map')" :description="__('Waar dit project staat en bij welke winkel het hoort.')">
        <div class="grid gap-4">
            <x-field :label="__('Winkel (.myshopify.com)')" for="shopify-store" error="store" :hint="__('De myshopify.com-naam, niet het eigen domein. De Shopify CLI en alle previewlinks gebruiken deze naam.')">
                <x-input id="shopify-store" wire:model="store" mono autocomplete="off" placeholder="winkel.myshopify.com" />
            </x-field>
            <x-field :label="__('Projectmap')" for="shopify-path" error="path" :hint="__('De themamap, een git-repo. BD Deck gooit deze map nooit weg, ook niet als je het project verwijdert.')">
                <x-input id="shopify-path" wire:model="path" mono autocomplete="off" />
            </x-field>
            <x-field :label="__('Eigen domein (optioneel)')" for="shopify-url" error="liveUrl" :hint="__('Alleen om de live winkel te openen en de uptime te bewaken.')">
                <x-input id="shopify-url" wire:model="liveUrl" mono autocomplete="off" placeholder="https://klant.nl" />
            </x-field>
        </div>
    </x-panel>

    <x-panel :title="__('Dev server')" :description="__('Elk project heeft een eigen poort, zodat meerdere dev servers tegelijk kunnen draaien.')">
        <div class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('Poort')" for="shopify-port" error="port">
                    <x-input id="shopify-port" type="number" wire:model="port" mono min="1024" max="65535" />
                </x-field>
                <x-field :label="__('Bereikbaar voor')" for="shopify-host" error="host">
                    <x-select id="shopify-host" wire:model="host">
                        <option value="127.0.0.1">{{ __('Alleen deze computer (aanbevolen)') }}</option>
                        <option value="0.0.0.0">{{ __('Hele netwerk (--host 0.0.0.0)') }}</option>
                    </x-select>
                </x-field>
            </div>
            <x-toggle wire:model="themeEditorSync" :label="__('Theme editor synchroniseren')" :description="__('Wijzigingen die je in de theme editor van je dev-thema maakt, komen terug in je lokale bestanden (--theme-editor-sync). Bij een verschil wint je lokale versie.')" />
        </div>
    </x-panel>

    <x-panel :title="__('Bestanden van de klant beschermen')" :description="__('Klanten passen deze bestanden zelf aan in de theme editor. Een push naar een bestaand preview-thema overschrijft ze nooit.')">
        <div class="grid gap-4">
            <x-field :label="__('Eén bestand of patroon per regel')" for="shopify-protect" error="protect">
                <textarea id="shopify-protect" wire:model="protect" rows="4" @disabled($protectionDisabled) class="w-full rounded-lg border border-line bg-surface px-3 py-2 font-mono text-[0.8125rem] text-ink transition-colors hover:border-line-strong focus:border-live focus:outline-none focus:ring-3 focus:ring-live/15 disabled:opacity-50"></textarea>
            </x-field>
            <x-toggle wire:model.live="protectionDisabled" :label="__('Bescherming uitzetten')" :description="__('Niet aanbevolen: een push kan dan werk van de klant overschrijven.')" />
            <div><x-button size="sm" variant="ghost" icon="rotate-ccw" wire:click="resetProtection">{{ __('Standaard terugzetten') }}</x-button></div>
        </div>
    </x-panel>

    <x-panel :title="__('Wachtwoordpagina van de winkel')" :description="__('Heeft de winkel een wachtwoordpagina, dan vraagt de dev server om het storefront-wachtwoord (dat is iets anders dan een Shopify-login).')">
        <div class="grid gap-3">
            @if ($hasPassword)
                <p class="flex items-center gap-2 text-sm text-success"><x-icon name="lock" :size="15" />{{ __('Opgeslagen in de kluis. Het gaat alleen als omgevingsvariabele naar de dev server en komt nooit in een log of repo.') }}</p>
            @endif
            <x-field :label="$hasPassword ? __('Nieuw wachtwoord (leeg laten om te behouden)') : __('Storefront-wachtwoord')" for="shopify-password" error="storePassword">
                <x-input id="shopify-password" type="password" wire:model="storePassword" autocomplete="new-password" />
            </x-field>
            @if ($hasPassword)
                <div><x-button size="sm" variant="danger-ghost" icon="trash" wire:click="forgetStorePassword">{{ __('Uit de kluis verwijderen') }}</x-button></div>
            @endif
        </div>
    </x-panel>

    <div><x-button variant="primary" wire:click="save">{{ __('Opslaan') }}</x-button></div>
</div>
