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

            @if ($hasUncommittedChanges)
                <x-field :label="__('Wat heb je aangepast?')" for="push-message" error="message" :hint="__('Er zijn wijzigingen die nog niet gecommit zijn. Dit bericht wordt de commit op dev.')">
                    <x-input id="push-message" wire:model="message" :placeholder="__('Bijvoorbeeld: telefoonnummer aangepast')" />
                </x-field>
            @else
                @error('message')<p class="text-[0.8125rem] text-danger">{{ $message }}</p>@enderror
            @endif

            <div class="grid gap-2 rounded-lg border border-line p-3">
                <x-toggle wire:model="flushCache" :label="__('Cache legen na de push')" :description="__('Nodig om de wijziging meteen te zien; zonder dit kan de servercache de oude pagina blijven tonen.')" />
                <x-toggle wire:model="uploads" :label="__('Nieuwe uploads meesturen')" :description="__('Alleen bestanden die op live nog niet bestaan. Live-uploads worden nooit overschreven.')" />
                @if ($site->isLaravel())
                    <p class="py-1 text-[0.8125rem] text-faint">{{ __('Laravel: de database blijft op live staan. Nieuwe migraties draaien automatisch na het uploaden (live wordt eerst bewaard), en bij een gewijzigde composer.lock volgt composer install.') }}</p>
                @elseif ($site->mode->hasShell())
                    <x-toggle wire:model.live="database" :label="__('Ook de database')" :description="__('Overschrijft de live database met je lokale. Live wordt eerst bewaard, live-plugins blijven aan en de lokale dev-gebruiker komt nooit op live.')" />
                @else
                    <p class="py-1 text-[0.8125rem] text-faint">{{ __('Database pushen kan alleen bij SSH-sites; deze site heeft alleen SFTP.') }}</p>
                @endif
                <details class="pt-1">
                    <summary class="cursor-pointer text-[0.8125rem] text-muted hover:text-ink">{{ __('Geavanceerd') }}</summary>
                    <div class="pt-2">
                        <x-toggle wire:model="force" :label="__('Ook als live intussen is aangepast')" :description="__('Normaal stopt de push als iemand buiten BD Deck om bestanden op live heeft gewijzigd. Die versies staan dan in de back-up.')" />
                    </div>
                </details>
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
            <x-button variant="live" icon="upload" wire:click="push" :disabled="! $loaded">{{ __('Zet live') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
