<div>
    <x-modal name="export-sites" :title="__('Sitelijst exporteren')" width="max-w-xl">
        @php $counts = $this->exportCounts; @endphp
        <div class="grid gap-4 text-sm">
            <p class="text-muted">
                {{ trans_choice('{0} Er zijn nog geen sites met een server om te delen.|{1} Eén site gaat mee in het bestand, zodat een collega hem niet zelf hoeft in te voeren.|[2,*] :count sites gaan mee in het bestand, zodat een collega ze niet allemaal zelf hoeft in te voeren.', $counts['shared']) }}
                @if ($counts['local'] > 0)
                    {{ trans_choice('{1} Eén lokale site zonder server blijft hier.|[2,*] :count lokale sites zonder server blijven hier.', $counts['local']) }}
                @endif
            </p>

            <div class="grid gap-3 rounded-lg bg-raised p-3">
                <h3 class="font-semibold">{{ __('Wat gaat er mee?') }}</h3>
                <div class="grid gap-1.5">
                    <p class="flex items-start gap-2"><x-icon name="check" :size="14" class="mt-0.5 shrink-0 text-success" /><span>{{ __('Per site: de naam, de server (gebruiker@host en poort), het pad op de server, SSH of SFTP, de provider, het live-adres en of het WordPress of Laravel is.') }}</span></p>
                    <p class="flex items-start gap-2"><x-icon name="x" :size="14" class="mt-0.5 shrink-0 text-danger" /><span>{{ __('Nooit: wachtwoorden en de kluis, SSH-sleutels en paden ernaar, favorieten, lokale mappen en lokale sites, en de WP-admin-gebruiker om live mee in te loggen.') }}</span></p>
                </div>
            </div>

            <div class="grid gap-1.5">
                <x-toggle wire:model.live="includeNotes" :label="__('Notities meenemen')" :description="trans_choice('{0} Je sites hebben nog geen notities.|{1} Eén site heeft een notitie.|[2,*] :count sites hebben een notitie.', $counts['notes'])" />
                @if ($includeNotes)
                    <p class="flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2 text-[0.8125rem] text-warning" role="alert">
                        <x-icon name="alert" :size="14" class="mt-0.5 shrink-0" />
                        <span>{{ __('Notities kunnen gevoelige informatie bevatten, zoals klantafspraken of inloggegevens. Lees ze na voordat je het bestand deelt.') }}</span>
                    </p>
                @endif
            </div>
        </div>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="primary" icon="download" wire:click="export" :disabled="$counts['shared'] === 0">{{ __('Opslaan als…') }}</x-button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="import-sites" :title="__('Sitelijst importeren')" width="max-w-2xl">
        @if ($importResult === null)
            @php
                $statuses = [
                    'new' => ['label' => __('Nieuw'), 'tone' => 'success'],
                    'same' => ['label' => __('Staat er al, zelfde gegevens'), 'tone' => 'neutral'],
                    'different' => ['label' => __('Staat er al, andere gegevens'), 'tone' => 'warning'],
                    'blocked' => ['label' => __('Staat er al'), 'tone' => 'neutral'],
                    'invalid' => ['label' => __('Ongeldig'), 'tone' => 'danger'],
                ];
                $chosen = collect($importRows)->whereIn('name', $importSelected)->whereIn('status', ['new', 'different'])->count();
            @endphp
            <div class="grid gap-4 text-sm">
                <p class="text-muted">{{ __('Kies welke sites je wilt overnemen. Er wordt nog niets op een server gedaan: BD Deck zet alleen de gegevens in je sitelijst en test daarna de verbinding.') }}</p>

                @if ($importRows === [])
                    <p class="text-muted">{{ __('Dit bestand bevat geen sites.') }}</p>
                @else
                    <ul class="grid max-h-[50vh] divide-y divide-line overflow-y-auto rounded-lg border border-line">
                        @foreach ($importRows as $index => $row)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5" wire:key="import-row-{{ $index }}">
                                <span class="grid size-5 shrink-0 place-items-center">
                                    @if ($row['status'] === 'new')
                                        <input type="checkbox" value="{{ $row['name'] }}" wire:model.live="importSelected" class="size-4 rounded border-line-strong accent-[var(--local)]" aria-label="{{ __(':site importeren', ['site' => $row['name']]) }}">
                                    @endif
                                </span>
                                <div class="grid min-w-0 flex-1 gap-0.5">
                                    <span class="truncate font-medium">{{ $row['name'] !== '' ? $row['name'] : __('(zonder naam)') }}</span>
                                    @if ($row['target'])
                                        <span class="truncate font-mono text-xs text-faint">{{ $row['target'] }}</span>
                                    @endif
                                    @if ($row['reason'])
                                        <span class="text-[0.8125rem] text-muted">{{ $row['reason'] }}</span>
                                    @endif
                                </div>
                                @if ($row['notes'])
                                    <span class="inline-flex items-center gap-1 text-xs text-muted" title="{{ __('Wordt alleen overgenomen als je zelf nog geen notitie bij deze site hebt.') }}"><x-icon name="file" :size="12" />{{ __('Notitie') }}</span>
                                @endif
                                @if ($row['status'] === 'different')
                                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-muted">
                                        <input type="checkbox" value="{{ $row['name'] }}" wire:model.live="importSelected" class="size-4 rounded border-line-strong accent-[var(--local)]">
                                        {{ __('bijwerken') }}
                                    </label>
                                @endif
                                <x-badge :tone="$statuses[$row['status']]['tone']">{{ $statuses[$row['status']]['label'] }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @error('importSelected')
                    <p class="text-[0.8125rem] text-danger">{{ $message }}</p>
                @enderror
            </div>
            <x-slot:footer>
                <x-button wire:click="closeImport">{{ __('Annuleren') }}</x-button>
                <x-button variant="primary" icon="plus" wire:click="import" :disabled="$chosen === 0">{{ trans_choice('{0} Niets om te importeren|{1} Eén site importeren|[2,*] :count sites importeren', $chosen) }}</x-button>
            </x-slot:footer>
        @else
            @php $added = count($importResult['added']); @endphp
            <div class="grid gap-4 text-sm">
                <ul class="grid gap-1.5">
                    <li class="flex items-center gap-2"><x-icon name="check" :size="14" class="text-success" />{{ trans_choice('{0} Geen sites toegevoegd|{1} Eén site toegevoegd|[2,*] :count sites toegevoegd', $added) }}</li>
                    @if ($importResult['updated'] !== [])
                        <li class="flex items-center gap-2"><x-icon name="refresh" :size="14" class="text-live-ink" />{{ trans_choice('{1} Eén site bijgewerkt|[2,*] :count sites bijgewerkt', count($importResult['updated'])) }}</li>
                    @endif
                    @if ($importResult['skipped'] !== [])
                        <li class="flex items-center gap-2"><x-icon name="more" :size="14" class="text-faint" />{{ trans_choice('{1} Eén site overgeslagen: :sites|[2,*] :count sites overgeslagen: :sites', count($importResult['skipped']), ['sites' => implode(', ', $importResult['skipped'])]) }}</li>
                    @endif
                    @foreach ($importResult['invalid'] as $invalid)
                        <li class="flex items-center gap-2 text-danger" wire:key="import-invalid-{{ $loop->index }}"><x-icon name="x" :size="14" />{{ __(':site niet geïmporteerd: :reason', ['site' => $invalid['name'] ?: '?', 'reason' => $invalid['reason']]) }}</li>
                    @endforeach
                </ul>

                @if ($added > 0)
                    <div class="grid gap-2 rounded-lg bg-raised p-3">
                        <h3 class="font-semibold">{{ __('Wat nu?') }}</h3>
                        <p class="text-muted">{{ __('De verbinding van de nieuwe sites wordt nu getest. De sleutel van je collega werkt niet voor jou: elke server moet ook jouw eigen SSH-sleutel kennen.') }}</p>
                        <ol class="grid list-decimal gap-1.5 pl-5">
                            <li>{{ __('Wacht tot de tests klaar zijn. Sites met "Verbinding mislukt" in de lijst kennen jouw sleutel nog niet.') }}</li>
                            <li>{{ __('Kopieer je publieke sleutel bij SSH-sleutels (of in stap 2 van Aan de slag).') }}</li>
                            <li>{{ __('Zet hem bij de hosting van die site: in de WPMU DEV Hub bij Hosting → SFTP/SSH, of in hPanel bij Geavanceerd → SSH-toegang. Geen toegang tot het hostingpaneel? Vraag het de collega die het beheert.') }}</li>
                            <li>{{ __('Open daarna de site en kies bij Verbinding de knop Testen.') }}</li>
                        </ol>
                    </div>
                @endif
            </div>
            <x-slot:footer>
                <x-button icon="key" :href="route('keys.index')" wire:navigate>{{ __('SSH-sleutels') }}</x-button>
                <x-button variant="primary" wire:click="closeImport">{{ __('Klaar') }}</x-button>
            </x-slot:footer>
        @endif
    </x-modal>
</div>
