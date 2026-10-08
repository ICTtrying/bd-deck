<div class="grid gap-6">
    <x-page-header :title="__('Kluis')" :description="__('Wachtwoorden, API-sleutels en hostinggegevens, versleuteld met je hoofdwachtwoord. Zonder ontgrendelde app zijn ze niet leesbaar, ook niet als iemand de database kopieert.')">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" wire:click="create">{{ __('Toevoegen') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->credentials->isEmpty() && $search === '')
        <x-empty-state icon="shield" :title="__('De kluis is leeg')" :description="__('Bewaar hier bijvoorbeeld SSH-wachtwoorden, de WPMU DEV API-sleutel of de inlog van het Hostinger-paneel.')">
            <x-button variant="primary" icon="plus" wire:click="create">{{ __('Eerste gegeven toevoegen') }}</x-button>
        </x-empty-state>
    @else
        <div class="relative w-full max-w-xs">
            <x-icon name="search" :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
            <x-input type="search" wire:model.live.debounce.200ms="search" :placeholder="__('Zoeken in de kluis')" class="pl-8" :aria-label="__('Kluis doorzoeken')" />
        </div>

        <x-panel :padding="false">
            <ul class="divide-y divide-line">
                @forelse ($this->credentials as $credential)
                    <li class="grid gap-2 px-5 py-3" wire:key="credential-{{ $credential->id }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-local-soft text-local-ink"><x-icon :name="$credential->kind->icon()" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $credential->label }}</p>
                                <p class="truncate text-[0.8125rem] text-muted">
                                    {{ $credential->kind->label() }}@if ($credential->site), {{ $credential->site->name }}@endif
                                    @if ($credential->username)<span class="font-mono text-faint"> {{ $credential->username }}</span>@endif
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                @if ($credential->username)
                                    <x-copy-button :text="$credential->username" :label="__('Gebruiker')" />
                                @endif
                                <x-button size="sm" :icon="$revealedId === $credential->id ? 'eye-off' : 'eye'" wire:click="reveal({{ $credential->id }})">{{ $revealedId === $credential->id ? __('Verbergen') : __('Tonen') }}</x-button>
                                @if ($credential->url)
                                    <x-button size="icon" variant="ghost" icon="external" :href="$credential->url" target="_blank" rel="noopener" :title="__('Openen')" />
                                @endif
                                <x-button size="icon" variant="ghost" icon="pencil" wire:click="edit({{ $credential->id }})" :title="__('Bewerken')" />
                                <x-button size="icon" variant="ghost" icon="trash" wire:click="delete({{ $credential->id }})" wire:confirm="{{ __('Dit gegeven definitief uit de kluis verwijderen?') }}" :title="__('Verwijderen')" />
                            </div>
                        </div>
                        @if ($revealedId === $credential->id && $revealedSecret !== null)
                            <div class="flex items-center gap-2 pl-11" x-data x-init="setTimeout(() => $wire.hide(), 30000)">
                                <code class="min-w-0 flex-1 truncate rounded-lg bg-raised px-3 py-1.5 font-mono text-[0.8125rem] select-all">{{ $revealedSecret }}</code>
                                <x-copy-button :text="$revealedSecret" />
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-6 text-muted">{{ __('Niets gevonden.') }}</li>
                @endforelse
            </ul>
        </x-panel>
    @endif

    <x-modal name="credential" :title="$editingId ? __('Gegeven bewerken') : __('Toevoegen aan de kluis')">
        <form wire:submit="save" id="credential-form" class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('Naam')" for="c-label" error="label" class="sm:col-span-2">
                    <x-input id="c-label" wire:model="label" :placeholder="__('Bijvoorbeeld: WPMU DEV API-sleutel')" />
                </x-field>
                <x-field :label="__('Soort')" for="c-kind" error="kind">
                    <x-select id="c-kind" wire:model="kind">
                        @foreach ($kinds as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-select>
                </x-field>
                <x-field :label="__('Site (optioneel)')" for="c-site" error="siteId">
                    <x-select id="c-site" wire:model="siteId">
                        <option value="">{{ __('Geen') }}</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </x-select>
                </x-field>
                <x-field :label="__('Gebruiker (optioneel)')" for="c-user" error="username">
                    <x-input id="c-user" wire:model="username" autocomplete="off" />
                </x-field>
                <x-field :label="__('Adres (optioneel)')" for="c-url" error="url">
                    <x-input id="c-url" type="url" wire:model="url" placeholder="https://" />
                </x-field>
                <x-field :label="__('Geheim')" for="c-secret" error="secret" class="sm:col-span-2" :hint="$editingId ? __('Leeg laten om het huidige geheim te houden.') : null">
                    <x-input id="c-secret" type="password" wire:model="secret" autocomplete="new-password" />
                </x-field>
                <x-field :label="__('Notities (optioneel)')" for="c-notes" error="notes" class="sm:col-span-2">
                    <x-textarea id="c-notes" wire:model="notes" rows="2" />
                </x-field>
            </div>
        </form>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button type="submit" form="credential-form" variant="primary" icon="lock">{{ __('Versleuteld opslaan') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
