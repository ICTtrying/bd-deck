<div class="grid gap-6">
    <x-page-header :title="__('SSH-sleutels')" :description="__('De publieke sleutels uit ~/.ssh op deze computer. Iemand anders met BD Deck ziet alleen zijn eigen sleutels; er zit niets van jou in de app. Publieke sleutels mag je delen: plak de standaardsleutel in de WPMU DEV Hub of bij Hostinger om zonder wachtwoord te verbinden. Private sleutels worden nooit gelezen of getoond.')">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" x-on:click="$dispatch('open-modal', 'generate-key')">{{ __('Nieuwe sleutel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->all === [])
        <x-empty-state icon="key" :title="__('Nog geen SSH-sleutel')" :description="__('Maak een sleutel aan; BD Deck gebruikt hem voor alle verbindingen met je servers.')">
            <x-button variant="primary" icon="plus" x-on:click="$dispatch('open-modal', 'generate-key')">{{ __('Sleutel aanmaken') }}</x-button>
        </x-empty-state>
    @else
        <div class="grid gap-4">
            @foreach ($this->all as $key)
                <section wire:key="key-{{ $key['name'] }}" @class(['grid gap-3 rounded-xl border bg-surface p-4', 'border-local/40' => $key['is_default'] || $generated === $key['name'], 'border-line' => ! $key['is_default'] && $generated !== $key['name']])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="grid gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-mono text-sm font-medium">{{ $key['name'] }}.pub</h2>
                                <x-badge>{{ $key['type'] }}@if ($key['bits']) {{ $key['bits'] }}@endif</x-badge>
                                @if ($key['is_default'])
                                    <x-badge tone="local">{{ __('Standaard voor BD Deck') }}</x-badge>
                                @endif
                                @unless ($key['has_private_key'])
                                    <x-badge tone="warning">{{ __('Geen private sleutel') }}</x-badge>
                                @endunless
                            </div>
                            <p class="text-[0.8125rem] text-muted">{{ $key['comment'] ?: __('Geen opmerking') }} <span class="font-mono text-faint">{{ $key['fingerprint'] }}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! $key['is_default'] && $key['has_private_key'])
                                <x-button size="sm" variant="ghost" wire:click="makeDefault('{{ $key['name'] }}')">{{ __('Als standaard gebruiken') }}</x-button>
                            @endif
                            <x-copy-button :text="$key['public_key']" />
                        </div>
                    </div>
                    <code class="block overflow-x-auto rounded-lg bg-raised px-3 py-2 font-mono text-xs leading-5 break-all whitespace-pre-wrap text-muted select-all">{{ $key['public_key'] }}</code>
                </section>
            @endforeach
        </div>
    @endif

    <x-modal name="generate-key" :title="__('Nieuwe SSH-sleutel')">
        <form wire:submit="generate" id="generate-key-form" class="grid gap-4">
            <p class="text-muted">{{ __('Maakt een ed25519-sleutel in ~/.ssh. Bestaande sleutels worden nooit overschreven.') }}</p>
            <x-field :label="__('Bestandsnaam')" for="key-name" error="name">
                <x-input id="key-name" wire:model="name" mono autocomplete="off" />
            </x-field>
            <x-field :label="__('Opmerking')" for="key-comment" error="comment" :hint="__('Zie je terug in het hostingpaneel, bijvoorbeeld je e-mailadres.')">
                <x-input id="key-comment" wire:model="comment" />
            </x-field>
            <x-field :label="__('Wachtwoordzin (optioneel)')" for="key-passphrase" error="passphrase" :hint="__('Met een wachtwoordzin vraagt elke verbinding erom, tenzij je ssh-agent hem onthoudt.')">
                <x-input id="key-passphrase" type="password" wire:model="passphrase" autocomplete="new-password" />
            </x-field>
        </form>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button type="submit" form="generate-key-form" variant="primary" icon="key">{{ __('Sleutel aanmaken') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
