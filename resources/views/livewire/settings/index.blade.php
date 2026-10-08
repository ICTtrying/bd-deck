<div class="grid max-w-3xl gap-6" x-on:theme-changed.window="window.bdDeck.applyTheme($event.detail.theme)">
    <x-page-header :title="__('Instellingen')" />

    <form wire:submit="save" class="grid gap-5">
        <x-panel :title="__('Weergave')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('Taal')" for="locale" error="locale">
                    <x-select id="locale" wire:model="locale">
                        <option value="nl">Nederlands</option>
                        <option value="en">English</option>
                    </x-select>
                </x-field>
                <div class="grid gap-1.5">
                    <span class="text-[0.8125rem] font-medium">{{ __('Thema') }}</span>
                    <div class="flex rounded-lg border border-line bg-raised p-0.5" role="radiogroup" aria-label="{{ __('Thema') }}">
                        @foreach ($themes as $option)
                            <button type="button" wire:click="$set('theme', '{{ $option->value }}')" x-on:click="window.bdDeck.applyTheme('{{ $option->value }}')" @class([
                                'flex h-8 flex-1 items-center justify-center gap-1.5 rounded-md text-[0.8125rem] transition-colors',
                                'bg-surface font-medium text-ink shadow-sm' => $theme === $option->value,
                                'text-muted hover:text-ink' => $theme !== $option->value,
                            ]) role="radio" aria-checked="{{ $theme === $option->value ? 'true' : 'false' }}">
                                <x-icon :name="match ($option) { \App\Enums\ThemePreference::Light => 'sun', \App\Enums\ThemePreference::Dark => 'moon', default => 'monitor' }" :size="14" />
                                {{ $option->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('Paden en programma\'s')">
            <div class="grid gap-4">
                <x-field :label="__('Map met lokale sites')" for="sites-dir" error="sitesDirectory" :hint="__('Hier bouwt wpopen elke site in een eigen map. Back-ups komen in .wpopen-backups daarbinnen.')">
                    <x-input id="sites-dir" wire:model="sitesDirectory" mono />
                </x-field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Editor')" for="editor" error="editor" :hint="__('Commando, bijvoorbeeld code, cursor of phpstorm.')">
                        <x-input id="editor" wire:model="editor" mono />
                    </x-field>
                    <x-field :label="__('Terminal')" for="terminal" error="terminal">
                        <x-select id="terminal" wire:model="terminal">
                            @foreach ($terminals as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-select>
                    </x-field>
                </div>
                <x-field :label="__('SSH-sleutel')" for="ssh-key" error="sshKeyPath" :hint="__('Private sleutel die wpopen op nieuwe servers zet. Kies hem ook op de pagina SSH-sleutels.')">
                    <x-input id="ssh-key" wire:model="sshKeyPath" mono />
                </x-field>
            </div>
        </x-panel>

        <x-panel :title="__('WordPress')">
            <div class="grid gap-3">
                <x-toggle wire:model="autoLoginLocal" :label="__('Automatisch inloggen in lokale wp-admin')" :description="__('Met een eenmalige link van WP-CLI, als gebruiker dev.')" />
                <x-toggle wire:model="autoLoginLive" :label="__('Automatisch inloggen in live wp-admin')" :description="__('Zet een eenmalig inlogbestand op de site dat na gebruik of na twee minuten zichzelf verwijdert.')" />
            </div>
        </x-panel>

        <x-panel :title="__('Beveiliging en meldingen')">
            <div class="grid gap-4">
                <x-field :label="__('Automatisch vergrendelen na')" for="auto-lock" error="autoLockMinutes" :hint="__('Minuten zonder muis of toetsenbord. 0 is nooit.')">
                    <div class="flex items-center gap-2">
                        <x-input id="auto-lock" type="number" min="0" max="480" wire:model="autoLockMinutes" class="w-24" />
                        <span class="text-muted">{{ __('minuten') }}</span>
                    </div>
                </x-field>
                <x-toggle wire:model="notifications" :label="__('Melding als een actie klaar is')" :description="__('Systeemmelding van Linux, ook als het venster niet zichtbaar is.')" />
            </div>
        </x-panel>

        <div class="flex justify-end">
            <x-button type="submit" variant="primary">{{ __('Instellingen opslaan') }}</x-button>
        </div>
    </form>

    <livewire:settings.premium-files />

    <x-panel :title="__('Hoofdwachtwoord wijzigen')" :description="__('Je kluis wordt meteen opnieuw versleuteld; bewaarde gegevens blijven gewoon bruikbaar.')">
        <form wire:submit="changePassword" class="grid gap-4 sm:grid-cols-3">
            <x-field :label="__('Huidig')" for="pw-current" error="currentPassword">
                <x-input id="pw-current" type="password" wire:model="currentPassword" autocomplete="current-password" />
            </x-field>
            <x-field :label="__('Nieuw')" for="pw-new" error="newPassword">
                <x-input id="pw-new" type="password" wire:model="newPassword" autocomplete="new-password" />
            </x-field>
            <x-field :label="__('Herhaal nieuw')" for="pw-confirm">
                <x-input id="pw-confirm" type="password" wire:model="newPassword_confirmation" autocomplete="new-password" />
            </x-field>
            <div class="sm:col-span-3 flex justify-end">
                <x-button type="submit" icon="lock">{{ __('Wachtwoord wijzigen') }}</x-button>
            </div>
        </form>
    </x-panel>

    <x-panel id="script" :title="__('wpopen-script')" :description="__('De app gebruikt hetzelfde script als je terminal. Alle logica voor bouwen, syncen en live zetten staat daarin.')">
        <div class="grid gap-4">
            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('Geïnstalleerd') }}</dt>
                <dd class="flex items-center gap-2">
                    <span class="font-mono text-[0.8125rem]">{{ $installedVersion ?? __('niet gevonden') }}</span>
                    @if ($installedVersion && $bundledVersion && version_compare($installedVersion, $bundledVersion, '<'))
                        <x-badge tone="warning">{{ __('Nieuwere versie beschikbaar') }}</x-badge>
                    @endif
                </dd>
                <dt class="text-muted">{{ __('Meegeleverd') }}</dt>
                <dd class="font-mono text-[0.8125rem]">{{ $bundledVersion ?? '–' }}</dd>
            </dl>
            <x-field :label="__('Pad naar het script')" for="script-path" error="scriptPath" :hint="__('Standaard ~/.local/bin/wpopen. Een eigen pad wordt nooit automatisch overschreven. Opslaan via Instellingen opslaan.')">
                <x-input id="script-path" wire:model="scriptPath" mono />
            </x-field>
            <div class="flex flex-wrap gap-2">
                <x-button icon="refresh" wire:click="reloadScript">{{ __('Script opnieuw laden') }}</x-button>
                <x-button variant="ghost" icon="download" wire:click="reinstallScript">{{ __('Meegeleverde versie installeren') }}</x-button>
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('Systeemcontrole')" :description="__('Controleert of git, DDEV, Docker, lftp en de andere hulpmiddelen aanwezig zijn.')">
        <x-slot:actions>
            <x-button size="sm" icon="activity" wire:click="runChecks">{{ __('Controleren') }}</x-button>
        </x-slot:actions>
        @if ($checks === [])
            <p class="text-muted">{{ __('Nog niet gecontroleerd.') }}</p>
        @else
            <ul class="grid gap-1.5 sm:grid-cols-2">
                @foreach ($checks as $check)
                    <li class="flex items-center gap-2 text-sm">
                        <x-icon :name="match ($check['status']) { 'ok' => 'check', 'fail' => 'x', default => 'info' }" :size="14" :class="match ($check['status']) { 'ok' => 'text-success', 'fail' => 'text-danger', default => 'text-faint' }" />
                        <span class="font-medium">{{ $check['label'] }}</span>
                        <span class="truncate font-mono text-xs text-faint">{{ $check['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</div>
