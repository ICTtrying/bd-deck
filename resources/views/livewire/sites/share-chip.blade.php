{{-- elke minuut opnieuw: de resterende tijd telt af en een verlopen link verdwijnt vanzelf --}}
<div @if ($site->isShared()) wire:poll.60s="refreshSite" @endif>
    @if ($site->isShared())
        <button type="button" x-on:click="$dispatch('open-share')" title="{{ $site->shareUrl() }}" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-live px-2 text-[0.8125rem] font-medium text-[#04222c] transition-colors hover:bg-live/85">
            <span class="size-1.5 animate-pulse rounded-full bg-current"></span>{{ __('Gedeeld · nog :time', ['time' => $this->remaining()]) }}
        </button>
    @else
        <button type="button" x-on:click="$dispatch('open-share')" title="{{ __('Tijdelijke link voor een klant') }}" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-surface/70 px-2 text-[0.8125rem] font-medium transition-colors hover:bg-surface">
            <x-icon name="share" :size="14" />{{ __('Delen') }}
        </button>
    @endif
</div>
