<div>
    @php $site = $this->site; @endphp
    <x-modal name="delete-site" :title="$site ? __(':site verwijderen', ['site' => $site->name]) : __('Site verwijderen')" tone="danger">
        @if ($site)
            <div class="grid gap-4">
                @if ($site->isLocalOnly())
                    <p class="text-muted">{{ __('Deze site bestaat alleen op je computer. De map :path en de DDEV-database worden weggegooid; er wordt eerst een back-up gemaakt.', ['path' => $site->projectDirectory()]) }}</p>
                @else
                    <p class="text-muted">{{ __('De site verdwijnt uit BD Deck en wpopen. De live site blijft altijd onaangetast.') }}</p>
                    @if ($site->isBuilt())
                        <x-toggle wire:model="purgeLocal" :label="__('Ook de lokale site weggooien')" :description="__(':path en de DDEV-database. Er wordt eerst een back-up gemaakt.', ['path' => $site->projectDirectory()])" />
                    @endif
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="danger" icon="trash" wire:click="delete">{{ __('Verwijderen') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
