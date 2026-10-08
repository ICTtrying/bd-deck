<div class="grid max-w-3xl gap-6">
    @if ($site->isLocalOnly())
        <x-page-header :title="__('Gegevens van :site', ['site' => $site->name])" :back="route('sites.show', $site)" />
        <x-empty-state icon="laptop" :title="__('Deze site heeft nog geen server')" :description="__('Verbindingsgegevens komen er vanzelf bij als je de site live zet.')">
            <x-button variant="live" icon="rocket" :href="route('sites.migrate', $site)" wire:navigate>{{ __('Live zetten') }}</x-button>
        </x-empty-state>
    @else
    <form wire:submit="save" class="grid gap-6">
        <x-page-header :title="__('Gegevens van :site', ['site' => $site->name])" :description="__('Wijzigingen gaan direct naar de sitelijst van wpopen, zodat ook de terminal ze kent.')" :back="route('sites.show', $site)" />

        @include('livewire.sites.partials.fields', ['creating' => false])

        <div class="flex items-center justify-end gap-2">
            <x-button :href="route('sites.show', $site)" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
            <x-button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Opslaan') }}</x-button>
        </div>
    </form>
    @endif

    <x-panel :title="__('Site verwijderen')" class="border-danger/30">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="max-w-prose text-muted">{{ __('Haalt de site uit BD Deck en wpopen. De live site blijft altijd onaangetast.') }}</p>
            <x-button variant="danger-ghost" icon="trash" x-on:click="$dispatch('confirm-delete-site', { siteId: {{ $site->id }} })">{{ __('Verwijderen') }}</x-button>
        </div>
    </x-panel>

    <livewire:sites.delete-dialog />
</div>
