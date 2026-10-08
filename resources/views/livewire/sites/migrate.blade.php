@php
    /** @var \App\Models\Site $site */
    $localOnly = $site->isLocalOnly();
    $steps = [
        [__('Nieuwe WordPress aanmaken'), __('Maak bij de nieuwe host een lege WordPress-site. WPMU DEV: Hub → Sites → Nieuwe site. Hostinger: Websites → Website toevoegen → WordPress. Een tijdelijk adres is prima.')],
        [__('SSH-sleutel plaatsen'), __('Zet je publieke sleutel bij de nieuwe site (zie Aan de slag), of vul hieronder één keer het SSH-wachtwoord in.')],
        [__('Gegevens invullen en starten'), __('BD Deck maakt eerst een back-up van de nieuwe server, zet daarna thema, plugins, uploads en database over en past alle adressen aan.')],
        [__('Domein omzetten'), __('Werkte je met een tijdelijk adres? Zet na het omzetten van de DNS het echte domein: bij SSH-sites onderaan deze pagina, bij alleen SFTP door nog een keer te verhuizen met het echte adres.')],
    ];
@endphp

<div class="grid max-w-3xl gap-6">
    <x-page-header
        :title="$localOnly ? __(':site live zetten', ['site' => $site->name]) : __(':site verhuizen', ['site' => $site->name])"
        :description="$localOnly
            ? __('Zet deze lokale site in één keer op een nieuwe WordPress-installatie bij WPMU DEV, Hostinger of elders. Daarna werkt alles zoals bij elke gekoppelde site: wijzigen, live zetten en terugdraaien.')
            : __('Verhuis deze site naar een nieuwe server, bijvoorbeeld van je oude host naar WPMU DEV of Hostinger. De lokale versie is de bron: haal eerst live op als daar nog iets nieuws staat.')"
        :back="route('sites.show', $site)"
    />

    <x-panel :title="__('Zo werkt het')">
        <ol class="grid gap-3">
            @foreach ($steps as $index => [$title, $text])
                <li class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-live-soft text-xs font-semibold text-live-ink">{{ $index + 1 }}</span>
                    <span class="grid gap-0.5"><span class="text-sm font-medium">{{ $title }}</span><span class="text-[0.8125rem] text-muted">{{ $text }}</span></span>
                </li>
            @endforeach
        </ol>
    </x-panel>

    @unless ($site->isBuilt())
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft px-4 py-3">
            <x-icon name="alert" class="text-warning" />
            <p class="min-w-0 flex-1 text-sm">{{ __('Bouw de site eerst lokaal: verhuizen gebeurt vanaf de lokale versie.') }}</p>
            <x-button size="sm" variant="primary" icon="hammer" wire:click="runQuick({{ $site->id }}, 'build')">{{ __('Lokaal bouwen') }}</x-button>
        </div>
    @endunless

    <form wire:submit="migrate" class="grid gap-5">
        <x-panel :title="__('Nieuwe server')" :description="__('De SSH- of SFTP-gegevens van de nieuwe WordPress-installatie.')">
            <div class="grid gap-4">
                <x-field :label="__('Plak een verbinding (optioneel)')" for="connection" :hint="__('Bijvoorbeeld ssh -p 65002 u123@1.2.3.4 uit Hostinger of sftp://jij@klant.tempurl.host uit de WPMU DEV Hub.')">
                    <x-input id="connection" wire:model.live.debounce.400ms="connection" mono placeholder="ssh -p 65002 gebruiker@server" autocomplete="off" spellcheck="false" />
                </x-field>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr_6rem]">
                    <x-field :label="__('Gebruiker')" for="user" error="form.user">
                        <x-input id="user" wire:model.blur="form.user" mono autocomplete="off" spellcheck="false" />
                    </x-field>
                    <x-field :label="__('Server')" for="host" error="form.host">
                        <x-input id="host" wire:model.blur="form.host" mono autocomplete="off" spellcheck="false" />
                    </x-field>
                    <x-field :label="__('Poort')" for="port" error="form.port">
                        <x-input id="port" type="number" min="1" max="65535" wire:model="form.port" mono />
                    </x-field>
                </div>
                <x-field :label="__('SSH-wachtwoord (optioneel)')" for="password" error="form.password" :hint="__('Alleen nodig als je SSH-sleutel nog niet op de nieuwe server staat.')">
                    <x-input id="password" type="password" wire:model="form.password" autocomplete="new-password" />
                </x-field>
                <x-toggle wire:model="form.savePassword" :label="__('Wachtwoord in de kluis bewaren')" :description="__('Versleuteld met je hoofdwachtwoord.')" />

                <details @if (! $form->discover) open @endif>
                    <summary class="cursor-pointer text-[0.8125rem] text-muted hover:text-ink">{{ __('Pad zelf opgeven') }}</summary>
                    <div class="grid gap-4 pt-3">
                        <x-toggle wire:model.live="form.discover" :label="__('Zelf zoeken')" :description="__('BD Deck zoekt wp-content op de server. Staan er meer installaties, dan kiest hij die met het domein in het pad.')" />
                        @unless ($form->discover)
                            <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                                <x-field :label="__('Pad naar wp-content')" for="remote" error="form.remotePath" :hint="__('Bijvoorbeeld domains/klant.nl/public_html/wp-content')">
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
                        @endunless
                    </div>
                </details>
            </div>
        </x-panel>

        <x-panel :title="__('Nieuwe site')">
            <div class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Adres van de nieuwe site')" for="live-url" error="form.liveUrl" :hint="__('Waar de nieuwe installatie nu bereikbaar is. Wijst de DNS nog naar de oude host? Gebruik dan het tijdelijke adres van de nieuwe host.')">
                        <x-input id="live-url" type="url" wire:model="form.liveUrl" placeholder="https://klant.nl" />
                    </x-field>
                    <x-field :label="__('Hosting')" for="provider" error="form.provider">
                        <x-select id="provider" wire:model="form.provider">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                            @endforeach
                        </x-select>
                    </x-field>
                </div>
                @unless ($localOnly)
                    <x-toggle wire:model="pullUploads" :label="__('Eerst alle uploads van de oude server ophalen')" :description="__('Lokaal komen afbeeldingen normaal via de oude server binnen; voor een verhuizing moeten ze echt mee.')" />
                    <x-toggle wire:model="keepOld" :label="__('Oude server bewaren als aparte site')" :description="__('Komt in de lijst als :name-oud, zodat je er nog bij kunt.', ['name' => $site->name])" />
                @endunless
            </div>
        </x-panel>

        <x-panel :title="__('Bevestigen')" class="border-live/40">
            <div class="grid gap-4">
                <p class="text-muted">{{ __('Alles op de nieuwe server (bestanden en database) wordt vervangen door :site. Vooraf wordt een back-up van de nieuwe server gemaakt, die je bij Back-ups kunt terugzetten.', ['site' => $site->name]) }}</p>
                <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="confirm-text" error="confirmText">
                    <x-input id="confirm-text" wire:model="confirmText" mono autocomplete="off" />
                </x-field>
            </div>
        </x-panel>

        <div class="flex items-center justify-end gap-2">
            <x-button :href="route('sites.show', $site)" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
            <x-button type="submit" variant="live" icon="rocket" wire:loading.attr="disabled" :disabled="! $site->isBuilt()">{{ $localOnly ? __('Live zetten') : __('Verhuizen') }}</x-button>
        </div>
    </form>

    @if (! $localOnly && $site->mode->hasShell())
        <x-panel :title="__('Alleen het domein omzetten')" :description="__('De site staat al op de juiste server, maar nog op een tijdelijk adres. Na het omzetten van de DNS zet je hier alle adressen in de live database om. Er wordt eerst een back-up gemaakt.')">
            <form wire:submit="changeDomain" class="flex flex-wrap items-end gap-3">
                <x-field :label="__('Nieuw domein')" for="new-domain" error="newDomain" class="min-w-64 flex-1">
                    <x-input id="new-domain" type="url" wire:model="newDomain" placeholder="https://klant.nl" />
                </x-field>
                <x-button type="submit" icon="globe" wire:loading.attr="disabled">{{ __('Domein omzetten') }}</x-button>
            </form>
        </x-panel>
    @endif
</div>
