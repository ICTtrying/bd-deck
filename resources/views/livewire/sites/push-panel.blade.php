<div>
    <x-modal name="push" :title="__(':site naar live zetten', ['site' => $site->name])" width="max-w-2xl">
        <div class="grid gap-5">
            <div class="flex items-center gap-3 rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]">
                <span class="inline-flex items-center gap-1.5 font-medium text-local-ink"><x-icon name="git-branch" :size="14" />dev</span>
                <x-icon name="arrow-right" :size="14" class="text-faint" />
                <span class="inline-flex items-center gap-1.5 font-medium text-local-ink">main</span>
                <x-icon name="arrow-right" :size="14" class="text-faint" />
                <span class="inline-flex items-center gap-1.5 font-medium text-live-ink"><x-icon name="globe" :size="14" />{{ $site->data('live_host') }}</span>
            </div>

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-semibold">{{ __('Bestanden die live gaan') }}</h3>
                    @if ($loaded)
                        <span class="text-[0.8125rem] text-muted">{{ trans_choice(':count bestand|:count bestanden', count($files)) }}</span>
                    @endif
                </div>
                <div class="max-h-56 overflow-y-auto rounded-lg border border-line">
                    @if (! $loaded)
                        <p class="flex items-center gap-2 px-3 py-3 text-muted"><x-icon name="loader" class="animate-spin" />{{ __('Wijzigingen ophalen…') }}</p>
                    @elseif ($files === [])
                        <p class="px-3 py-3 text-muted">{{ __('Geen gewijzigde bestanden: lokaal is gelijk aan live.') }}</p>
                    @else
                        <ul class="divide-y divide-line font-mono text-xs">
                            @foreach ($files as $file)
                                <li class="flex items-center gap-2 px-3 py-1.5" wire:key="file-{{ md5($file) }}"><x-icon name="file" :size="13" class="text-faint" />{{ $file }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if ($deleted !== [])
                    <p class="text-[0.8125rem] text-muted">{{ trans_choice('Lokaal verwijderd en blijft op live staan: :files|Lokaal verwijderd en blijven op live staan: :files', count($deleted), ['files' => implode(', ', $deleted)]) }}</p>
                @endif
            </div>

            @if ($changedOnLive !== [])
                <div class="grid gap-3 rounded-lg border border-warning/40 bg-warning-soft p-3">
                    <div class="flex items-start gap-2 text-sm">
                        <x-icon name="alert" class="mt-0.5 text-warning" />
                        <div class="grid gap-1">
                            <p class="font-medium">{{ trans_choice('Dit bestand is op live aangepast sinds je laatste sync|Deze bestanden zijn op live aangepast sinds je laatste sync', count($changedOnLive)) }}</p>
                            <p class="text-[0.8125rem] text-muted">{{ __('Iemand heeft buiten BD Deck om iets op live veranderd. Live zetten overschrijft dat.') }}</p>
                        </div>
                    </div>
                    <ul class="grid gap-0.5 pl-6 font-mono text-xs">
                        @foreach ($changedOnLive as $file)
                            <li wire:key="drift-{{ md5($file) }}">{{ $file }}</li>
                        @endforeach
                    </ul>
                    <div class="grid gap-2 pl-6">
                        @if ($hasUncommittedChanges)
                            <p class="text-[0.8125rem] text-muted">{{ __('Wil je de live-versie behouden? Commit eerst je eigen werk; dan kun je live hier eerst ophalen.') }}</p>
                        @else
                            <div><x-button size="sm" icon="arrow-left" wire:click="pullFirst">{{ __('Eerst live ophalen en samenvoegen') }}</x-button></div>
                        @endif
                        <x-toggle wire:model.live="force" :label="__('Toch overschrijven')" :description="__('De huidige live-versies worden eerst in de back-up bewaard.')" />
                    </div>
                    @error('force')<p class="pl-6 text-[0.8125rem] text-danger">{{ $message }}</p>@enderror
                </div>
            @endif

            @if ($hasUncommittedChanges)
                <x-field :label="__('Wat heb je aangepast?')" for="push-message" error="message" :hint="__('Voorstel op basis van de bestanden; pas het gerust aan. Dit wordt de commit op dev.')">
                    <x-input id="push-message" wire:model="message" wire:keydown.enter="push" :placeholder="__('Bijvoorbeeld: telefoonnummer aangepast')" />
                </x-field>
            @else
                @error('message')<p class="text-[0.8125rem] text-danger">{{ $message }}</p>@enderror
            @endif

            @if ($steps !== [])
                <div class="grid gap-1.5">
                    <h3 class="text-sm font-semibold">{{ __('Wat er daarna gebeurt') }}</h3>
                    <ul class="grid gap-1 text-[0.8125rem] text-muted">
                        @foreach ($steps as $step)
                            <li class="flex items-start gap-2" wire:key="step-{{ md5($step) }}"><x-icon name="check" :size="13" class="mt-0.5 text-live-ink" />{{ $step }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-2 rounded-lg border border-line p-3">
                <x-toggle wire:model="flushCache" :label="__('Cache legen na de push')" :description="__('Nodig om de wijziging meteen te zien; zonder dit kan de servercache de oude pagina blijven tonen.')" />
                <x-toggle wire:model="uploads" :label="__('Nieuwe uploads meesturen')" :description="__('Alleen bestanden die op live nog niet bestaan. Live-uploads worden nooit overschreven.')" />
                @if ($site->isLaravel())
                    <p class="py-1 text-[0.8125rem] text-faint">{{ __('Laravel: de database blijft op live staan; structuurwijzigingen gaan via migraties.') }}</p>
                @elseif ($site->mode->hasShell())
                    <x-toggle wire:model.live="database" :label="__('Ook de database')" :description="__('Overschrijft de live database met je lokale. Live wordt eerst bewaard, live-plugins blijven aan en de lokale dev-gebruiker komt nooit op live.')" />
                @else
                    <p class="py-1 text-[0.8125rem] text-faint">{{ __('Database pushen kan alleen bij SSH-sites; deze site heeft alleen SFTP.') }}</p>
                @endif
            </div>

            @if ($database)
                <div class="grid gap-3 rounded-lg border border-danger/30 bg-danger-soft p-3">
                    <p class="flex items-start gap-2 text-sm text-danger"><x-icon name="alert" class="mt-0.5" />{{ __('De database op live wordt vervangen. Bestellingen, formulierinzendingen en reacties van na je laatste ophaalmoment gaan dan verloren.') }}</p>
                    <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="push-confirm" error="confirmText">
                        <x-input id="push-confirm" wire:model="confirmText" autocomplete="off" mono />
                    </x-field>
                </div>
            @endif

            <p class="text-[0.8125rem] text-muted">{{ __('Voor het uploaden worden de huidige live-versies van deze bestanden bewaard, zodat je de push met één klik kunt terugdraaien onder Back-ups.') }}</p>
        </div>

        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="live" icon="upload" wire:click="push" :disabled="! $loaded || ($files === [] && ! $uploads && ! $database) || ($changedOnLive !== [] && ! $force)">
                {{ $loaded && $files !== [] ? trans_choice('Zet :count bestand live|Zet :count bestanden live', count($files)) : __('Zet live') }}
            </x-button>
        </x-slot:footer>
    </x-modal>
</div>
