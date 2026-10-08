<div class="grid max-w-3xl gap-6">
    <form wire:submit="save" class="grid gap-6">
        <x-page-header :title="__('Gegevens van :site', ['site' => $site->name])" :description="__('Wijzigingen gaan direct naar de sitelijst van wpopen, zodat ook de terminal ze kent.')" :back="route('sites.show', $site)" />

        @include('livewire.sites.partials.fields', ['creating' => false])

        <div class="flex items-center justify-end gap-2">
            <x-button :href="route('sites.show', $site)" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
            <x-button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Opslaan') }}</x-button>
        </div>
    </form>

    <x-panel :title="__('Site verwijderen')" class="border-danger/30">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="max-w-prose text-muted">{{ __('Haalt de site uit BD Deck en wpopen. De live site blijft altijd onaangetast.') }}</p>
            <x-button variant="danger-ghost" icon="trash" x-on:click="$dispatch('open-modal', 'delete-site')">{{ __('Verwijderen') }}</x-button>
        </div>
    </x-panel>

    <x-modal name="delete-site" :title="__(':site verwijderen', ['site' => $site->name])" tone="danger">
        <div class="grid gap-4">
            <p class="text-muted">{{ __('De site verdwijnt uit de lijst. Live verandert niet.') }}</p>
            @if ($site->isBuilt())
                <x-toggle wire:model="purgeLocal" :label="__('Ook de lokale site weggooien')" :description="__(':path en de DDEV-database. Er wordt eerst een back-up gemaakt.', ['path' => $site->projectDirectory()])" />
            @endif
            <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="delete-confirm" error="confirmText">
                <x-input id="delete-confirm" wire:model="confirmText" mono autocomplete="off" />
            </x-field>
        </div>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="danger" icon="trash" wire:click="delete">{{ __('Verwijderen') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
