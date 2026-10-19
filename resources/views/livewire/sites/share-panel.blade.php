@php
    /** @var \App\Models\Site $site */
    $run = $this->run;
    $making = $run?->status->isActive() ?? false;
    $failed = $run !== null && ! $making && $run->status !== \App\Enums\RunStatus::Succeeded;
    $url = $site->shareUrl();
@endphp

<div @if ($making) wire:poll.1500ms="poll" @endif>
    <x-modal name="share" :title="__(':site delen', ['site' => $site->name])" width="max-w-xl">
        <div class="grid gap-5">
            @if ($making)
                <div class="grid gap-3 rounded-lg border border-live/30 bg-live-soft/60 p-4" aria-live="polite">
                    <p class="flex items-center gap-2 text-sm font-medium"><x-icon name="loader" class="animate-spin text-live-ink" />{{ __('Link wordt gemaakt…') }}</p>
                    <p class="text-[0.8125rem] text-muted">{{ __('Dit duurt meestal 10 tot 30 seconden. Je kunt dit venster gerust sluiten; de link staat straks bij "Delen".') }}</p>
                    @if ($run->steps()->isNotEmpty())
                        <p class="flex items-center gap-1.5 text-[0.8125rem] text-live-ink"><x-icon name="arrow-right" :size="12" />{{ $run->steps()->last() }}</p>
                    @endif
                </div>
            @elseif ($url)
                <div class="grid gap-2">
                    <p class="flex items-center gap-2 text-sm font-medium text-success"><x-icon name="check" :size="15" />{{ __('Gedeeld tot :time', ['time' => $site->shareExpiresAt()?->format('H:i')]) }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-input :value="$url" readonly mono class="min-w-0 flex-1" aria-label="{{ __('Deellink') }}" />
                        <x-copy-button :text="$url" />
                        <x-button size="sm" icon="external" wire:click="launch({{ $site->id }}, 'share')">{{ __('Openen') }}</x-button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-sm font-semibold">{{ __('Bericht voor de klant') }}</h3>
                        <x-copy-button :text="$this->clientMessage()" :label="__('Bericht kopiëren')" />
                    </div>
                    <p class="whitespace-pre-line rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]">{{ $this->clientMessage() }}</p>
                </div>

                <p class="text-[0.8125rem] text-muted">{{ __('Laat je computer aan staan zolang de klant kijkt. Wat je lokaal aanpast, ziet de klant na verversen meteen.') }}</p>
            @else
                <div class="grid gap-2 text-[0.8125rem] text-muted">
                    <p class="text-sm text-ink">{{ __('Maak een tijdelijke openbare link naar je lokale versie, zodat een klant de nieuwe site kan bekijken zonder testserver.') }}</p>
                    <ul class="grid gap-1.5">
                        <li class="flex items-start gap-2"><x-icon name="laptop" :size="14" class="mt-0.5 text-local-ink" />{{ __('De klant ziet de site vanaf jouw computer: die moet aan blijven staan.') }}</li>
                        @unless ($site->isLaravel())
                            <li class="flex items-start gap-2"><x-icon name="lock" :size="14" class="mt-0.5 text-local-ink" />{{ __('WP-admin en inloggen zijn via de link niet bereikbaar; formulieren op de site werken wel.') }}</li>
                        @endunless
                        <li class="flex items-start gap-2"><x-icon name="mail" :size="14" class="mt-0.5 text-local-ink" />{{ __('Mail die de site verstuurt, komt in Mailpit terecht en niet bij echte ontvangers.') }}</li>
                        <li class="flex items-start gap-2"><x-icon name="cloud" :size="14" class="mt-0.5 text-local-ink" />{{ __('Via Cloudflare, zonder account. De eerste keer installeert BD Deck daarvoor zelf cloudflared.') }}</li>
                    </ul>
                </div>

                <x-field :label="__('Hoe lang moet de link werken?')" for="share-minutes" error="minutes">
                    <x-select id="share-minutes" wire:model="minutes">
                        @foreach ($this->durations() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                </x-field>

                @if ($failed)
                    <div class="grid gap-1 rounded-lg border border-danger/30 bg-danger-soft px-3 py-2.5 text-[0.8125rem]">
                        <p class="font-medium text-danger">{{ __('De link kon niet gemaakt worden.') }}</p>
                        @if ($run->lastMessage())
                            <p class="text-muted">{{ ltrim($run->lastMessage(), '✗ ') }}</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        <x-slot:footer>
            @if ($url && ! $making)
                <x-button variant="danger-ghost" icon="square" wire:click="stop">{{ __('Link stoppen') }}</x-button>
                <x-button x-on:click="open = false">{{ __('Sluiten') }}</x-button>
            @else
                <x-button x-on:click="open = false">{{ $making ? __('Sluiten') : __('Annuleren') }}</x-button>
                @unless ($making)
                    <x-button variant="live" icon="link" wire:click="start">{{ __('Link maken') }}</x-button>
                @endunless
            @endif
        </x-slot:footer>
    </x-modal>
</div>
