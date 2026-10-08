{{-- Gedeelde velden voor toevoegen en bewerken. $creating bepaalt wat er zichtbaar is. --}}
<div class="grid gap-5">
    <x-panel :title="__('Verbinding')" :description="__('De SSH- of SFTP-gegevens uit het hostingpaneel.')">
        <div class="grid gap-4">
            @if ($creating)
                <x-field :label="__('Plak een verbinding (optioneel)')" for="connection" :hint="__('Bijvoorbeeld sftp://jij@klant.tempurl.host of ssh -p 65002 u123@1.2.3.4. De velden hieronder worden dan ingevuld.')">
                    <x-input id="connection" wire:model.live.debounce.400ms="connection" mono placeholder="sftp://gebruiker@server:poort" autocomplete="off" spellcheck="false" />
                </x-field>
            @endif

            <div class="grid gap-4 sm:grid-cols-[1fr_2fr_6rem]">
                <x-field :label="__('Gebruiker')" for="user" error="form.user">
                    <x-input id="user" wire:model.blur="form.user" mono autocomplete="off" spellcheck="false" />
                </x-field>
                <x-field :label="__('Server')" for="host" error="form.host">
                    <x-input id="host" wire:model.blur="form.host" mono placeholder="klant.tempurl.host" autocomplete="off" spellcheck="false" />
                </x-field>
                <x-field :label="__('Poort')" for="port" error="form.port">
                    <x-input id="port" type="number" min="1" max="65535" wire:model="form.port" mono />
                </x-field>
            </div>

            @if ($creating)
                <x-field :label="__('SSH-wachtwoord (optioneel)')" for="password" error="form.password" :hint="__('Alleen nodig als je SSH-sleutel nog niet op de server staat: BD Deck zet hem er dan één keer op. Bij WPMU DEV voeg je de sleutel meestal toe in de Hub.')">
                    <x-input id="password" type="password" wire:model="form.password" autocomplete="new-password" />
                </x-field>
                <x-toggle wire:model="form.savePassword" :label="__('Wachtwoord in de kluis bewaren')" :description="__('Versleuteld met je hoofdwachtwoord.')" />
            @endif

            <details @if (! $creating && $form->extraOptions !== '') open @endif>
                <summary class="cursor-pointer text-[0.8125rem] text-muted hover:text-ink">{{ __('Extra SSH-opties') }}</summary>
                <div class="pt-3">
                    <x-field :label="__('Opties')" for="extra" error="form.extraOptions" :hint="__('Bijvoorbeeld -i ~/.ssh/klant voor een andere sleutel.')">
                        <x-input id="extra" wire:model="form.extraOptions" mono autocomplete="off" spellcheck="false" />
                    </x-field>
                </div>
            </details>
        </div>
    </x-panel>

    <x-panel :title="__('Project op de server')" :description="__('BD Deck herkent zelf of het WordPress of Laravel is.')">
        <div class="grid gap-4">
            @if ($creating)
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                    @foreach ([true => [__('Zelf zoeken'), __('BD Deck zoekt op de server naar WordPress (wp-content) of Laravel (artisan). Kopieën slaat hij over; meerdere echte sites worden elk een eigen site.')], false => [__('Pad opgeven'), __('Je weet waar het project staat, of de server laat zoeken niet toe.')]] as $value => [$label, $text])
                        <label @class(['grid cursor-pointer gap-1 rounded-lg border p-3 transition-colors', 'border-local bg-local-soft' => $form->discover === (bool) $value, 'border-line hover:border-line-strong' => $form->discover !== (bool) $value])>
                            <span class="flex items-center gap-2 text-sm font-medium">
                                <input type="radio" wire:model.live="form.discover" value="{{ $value ? '1' : '0' }}" class="accent-[var(--local)]">
                                {{ $label }}
                            </span>
                            <span class="text-[0.8125rem] text-muted">{{ $text }}</span>
                        </label>
                    @endforeach
                </div>
            @endif

            @if (! $form->discover)
                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <x-field :label="__('Pad naar het project')" for="remote" error="form.remotePath" :hint="__('WordPress: de map wp-content of de map erboven (public_html). Laravel: de map met artisan. Absoluut of ten opzichte van de SFTP-map.')">
                        <x-input id="remote" wire:model="form.remotePath" mono autocomplete="off" spellcheck="false" />
                    </x-field>
                    <x-field :label="__('Toegang')" for="mode" error="form.mode">
                        <x-select id="mode" wire:model="form.mode">
                            @foreach ($modes as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </x-select>
                    </x-field>
                </div>
            @endif
        </div>
    </x-panel>

    <x-panel :title="__('Over de site')">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field :label="__('Naam')" for="name" error="form.name" :hint="$creating ? __('Wordt ook de map in je sites-map en het lokale adres naam.ddev.site.') : null">
                <x-input id="name" wire:model="form.name" mono autocomplete="off" spellcheck="false" />
            </x-field>
            <x-field :label="__('Hosting')" for="provider" error="form.provider">
                <x-select id="provider" wire:model="form.provider">
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                    @endforeach
                </x-select>
            </x-field>
            <x-field :label="__('Live-adres (optioneel)')" for="live-url" error="form.liveUrl" :hint="__('Leeg laten: BD Deck neemt het adres uit de live database.')">
                <x-input id="live-url" type="url" wire:model="form.liveUrl" placeholder="https://klant.nl" />
            </x-field>
            @unless ($creating)
                <x-field :label="__('Live inloggen als (optioneel)')" for="login-user" error="form.liveLoginUser" :hint="__('Gebruikersnaam voor automatisch inloggen in live wp-admin. Leeg: de eerste beheerder.')">
                    <x-input id="login-user" wire:model="form.liveLoginUser" mono autocomplete="off" />
                </x-field>
            @endunless
        </div>
    </x-panel>
</div>
