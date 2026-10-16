@php
    /** @var \App\Models\Site $site */
    $subsites = $site->subsites();
@endphp

<div>
    <x-panel :title="__('Multisite')" :description="$site->isMultisite()
        ? trans_choice(':count subsite|:count subsites', count($subsites))
        : __('Meerdere sites, elk met een eigen adres, in één WordPress')">
        @unless ($site->isMultisite())
            <x-slot:actions>
                <x-button size="sm" icon="grid" x-on:click="$dispatch('open-modal', 'convert-multisite')" :disabled="! $site->isBuilt() || $this->busy">{{ __('Omzetten') }}</x-button>
            </x-slot:actions>
            <p class="text-muted">{{ $site->isBuilt()
                ? __('Zet deze site met één klik om naar een multisite. Daarna voeg je hier subsites toe, zoals winkel.:host of een eigen domein.', ['host' => parse_url($site->localUrl(), PHP_URL_HOST)])
                : __('Bouw de site eerst lokaal.') }}</p>
        @else
            <div class="grid gap-4">
                @if ($subsites !== [])
                    <ul class="-my-1 divide-y divide-line">
                        @foreach ($subsites as $subsite)
                            <li wire:key="subsite-{{ $subsite['id'] }}" class="flex items-center gap-2 py-2">
                                <span class="min-w-0 flex-1 truncate font-mono text-[0.8125rem]">{{ parse_url($subsite['url'], PHP_URL_HOST) }}</span>
                                <x-button size="icon" variant="ghost" icon="globe" wire:click="open({{ $subsite['id'] }}, false)" :title="__('Website')" />
                                <x-button size="icon" variant="ghost" icon="wordpress" wire:click="open({{ $subsite['id'] }}, true)" :title="__('WP-admin')" />
                                <x-button size="icon" variant="danger-ghost" icon="trash" wire:click="confirmRemove({{ $subsite['id'] }})" :disabled="$this->busy" :title="__('Verwijderen')" />
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addSubsite" class="grid gap-1.5">
                    <label for="subsite-address" class="text-[0.8125rem] font-medium text-ink">{{ __('Subsite toevoegen') }}</label>
                    <div class="flex gap-2">
                        <x-input id="subsite-address" wire:model="address" mono placeholder="winkel" autocomplete="off" spellcheck="false" />
                        <x-button type="submit" icon="plus" wire:loading.attr="disabled" :disabled="$this->busy">{{ __('Toevoegen') }}</x-button>
                    </div>
                    @error('address')
                        <p class="text-[0.8125rem] text-danger">{{ $message }}</p>
                    @else
                        <p class="text-[0.8125rem] text-muted">{{ __('Een naam wordt naam.:host; met een punt is het een eigen domein, bv. klant2.test.', ['host' => parse_url($site->localUrl(), PHP_URL_HOST)]) }}</p>
                    @enderror
                </form>

                <p class="text-[0.8125rem] text-muted">{{ __('Inloggen op een subsite: dev / dev. Netwerkbeheer staat in WP-admin onder Mijn sites.') }}</p>
            </div>
        @endunless
    </x-panel>

    <x-modal name="convert-multisite" :title="__('Omzetten naar multisite')">
        <p class="text-muted">{{ __('BD Deck zet :site om naar een WordPress-multisite met subdomeinen. Terugdraaien kan niet; een multisite kan BD Deck ook niet live zetten. Prima om iets uit te proberen: gooi de site daarna gewoon weg.', ['site' => $site->name]) }}</p>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="primary" wire:click="convert">{{ __('Omzetten') }}</x-button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="remove-subsite" :title="__('Subsite verwijderen')" tone="danger">
        <p class="text-muted">{{ __(':url en alle inhoud ervan worden uit de lokale multisite verwijderd.', ['url' => $removingUrl ?? '']) }}</p>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="danger" wire:click="removeSubsite">{{ __('Verwijderen') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
