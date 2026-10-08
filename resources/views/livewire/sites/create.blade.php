<form wire:submit="save" class="grid max-w-3xl gap-6">
    <x-page-header :title="__('Site toevoegen')" :description="__('Koppel een WordPress-site bij WPMU DEV, Hostinger of elders. Daarna bouw je hem met één klik lokaal na.')" :back="route('dashboard')">
        <x-slot:actions>
            <x-button icon="search" wire:click="import">{{ __('Bestaande verbindingen zoeken') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('livewire.sites.partials.fields', ['creating' => true])

    <div class="flex items-center justify-end gap-2">
        <x-button :href="route('dashboard')" wire:navigate variant="ghost">{{ __('Annuleren') }}</x-button>
        <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Site toevoegen') }}</x-button>
    </div>
</form>
