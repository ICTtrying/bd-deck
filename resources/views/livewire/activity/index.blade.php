<div class="grid gap-6" @if ($this->hasActive) wire:poll.2s @endif>
    <x-page-header :title="__('Activiteit')" :description="__('Alles wat BD Deck met wpopen heeft uitgevoerd, met de volledige uitvoer.')" />

    <div class="flex flex-wrap items-center gap-3">
        <div class="relative w-full max-w-xs">
            <x-icon name="search" :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
            <x-input type="search" wire:model.live.debounce.250ms="search" :placeholder="__('Zoek op site of actie')" class="pl-8" :aria-label="__('Activiteit zoeken')" />
        </div>
        <div class="w-44">
            <x-select wire:model.live="status" :aria-label="__('Status')">
                <option value="">{{ __('Elke status') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-select>
        </div>
    </div>

    @if ($this->runs->isEmpty())
        <x-empty-state icon="history" :title="__('Geen activiteit')" :description="$search || $status ? __('Niets gevonden met deze filters.') : __('Zodra je een site bouwt, test of live zet, zie je hier precies wat er gebeurde.')" />
    @else
        <x-panel :padding="false">
            <ul class="divide-y divide-line">
                @foreach ($this->runs as $run)
                    <li wire:key="run-{{ $run->id }}"><x-run-row :run="$run" /></li>
                @endforeach
            </ul>
            @if ($this->runs->hasPages())
                <div class="border-t border-line px-5 py-3">{{ $this->runs->links() }}</div>
            @endif
        </x-panel>
    @endif
</div>
