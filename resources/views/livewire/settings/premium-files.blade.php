<x-panel id="premium" :title="__('Enfold en WPMU DEV-plugins')" :description="__('Elke nieuwe lokale site krijgt hieruit het thema en de plugins, geïnstalleerd en geactiveerd. Per thema of plugin telt de nieuwste zip.')">
    <x-slot:actions>
        <x-button size="sm" variant="primary" icon="plus" wire:click="choose">{{ __('Zips toevoegen') }}</x-button>
    </x-slot:actions>

    @if ($items->isEmpty())
        <p class="text-muted">{{ __('Nog niets toegevoegd. Kies de zip van Enfold (het ThemeForest-pakket mag ook) en de zips van het WPMU DEV Dashboard, Smush, Hummingbird, Defender, Snapshot, SmartCrawl, Forminator en Beehive.') }}</p>
    @else
        <ul class="divide-y divide-line">
            @foreach ($items as $item)
                <li class="flex items-center gap-3 py-2.5" wire:key="premium-{{ $item['file'] }}">
                    <x-badge :tone="$item['kind'] === 'theme' ? 'info' : 'neutral'">{{ match ($item['kind']) { 'theme' => __('Thema'), 'plugin' => __('Plugin'), default => '?' } }}</x-badge>
                    <div class="grid min-w-0 flex-1 leading-snug">
                        <span class="truncate text-sm font-medium">{{ $item['slug'] ?? $item['file'] }}</span>
                        <span class="truncate font-mono text-xs text-faint">{{ $item['file'] }} · {{ \Illuminate\Support\Number::fileSize($item['size']) }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($item['modified'])->diffForHumans() }}</span>
                    </div>
                    @if ($item['slug'] === null)
                        <span class="text-xs text-danger">{{ __('Geen thema of plugin') }}</span>
                    @elseif ($item['active'])
                        <span class="text-xs text-success">{{ __('Wordt gebruikt') }}</span>
                    @else
                        <span class="text-xs text-faint">{{ __('Oudere versie') }}</span>
                    @endif
                    <x-button size="icon" variant="danger-ghost" icon="trash" wire:click="remove({{ Js::from($item['file']) }})" wire:confirm="{{ __('Deze zip verwijderen?') }}" :title="__('Verwijderen')" />
                </li>
            @endforeach
        </ul>
    @endif
    <p class="mt-3 font-mono text-xs text-faint">{{ $directory }}</p>
</x-panel>
