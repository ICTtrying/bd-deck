<div>
    <x-modal name="safe-update" :title="__(':site veilig bijwerken', ['site' => $site->name])" width="max-w-2xl">
        @php $items = $this->items(); @endphp
        <div class="grid gap-5">
            <p class="text-sm text-muted">{{ __('BD Deck haalt eerst de site van live op, werkt hem lokaal bij en controleert een paar pagina\'s. Pas als alles werkt, gaan de updates naar live.') }}</p>

            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1.5 rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]">
                @foreach ([
                    ['icon' => 'download', 'label' => __('Ophalen van live'), 'live' => false],
                    ['icon' => 'refresh', 'label' => __('Lokaal bijwerken'), 'live' => false],
                    ['icon' => 'check', 'label' => __('Pagina\'s controleren'), 'live' => false],
                    ['icon' => 'archive', 'label' => __('Back-up van live'), 'live' => true],
                    ['icon' => 'upload', 'label' => __('Live zetten'), 'live' => true],
                ] as $step)
                    <li @class(['flex items-center gap-1.5', 'text-faint line-through' => $step['live'] && ! $live])>
                        <span class="grid size-5 place-items-center rounded-full bg-surface text-[0.6875rem] font-semibold tabular-nums">{{ $loop->iteration }}</span>
                        <x-icon :name="$step['icon']" :size="13" :class="$step['live'] ? 'text-live-ink' : 'text-local-ink'" />
                        <span class="font-medium">{{ $step['label'] }}</span>
                        @unless ($loop->last)<x-icon name="chevron-right" :size="13" class="text-faint" />@endunless
                    </li>
                @endforeach
            </ol>

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-semibold">{{ __('Updates') }}</h3>
                    <span class="text-[0.8125rem] text-muted">{{ trans_choice(':count gekozen|:count gekozen', count($selected)) }}</span>
                </div>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-line">
                    @if ($items->isEmpty())
                        <p class="px-3 py-3 text-muted">{{ __('Geen updates bekend. Controleer eerst welke updates er op live zijn.') }}</p>
                    @else
                        <ul class="divide-y divide-line text-sm">
                            @foreach ($items as $item)
                                <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="safe-update-{{ md5($item['key']) }}">
                                    <label @class(['flex min-w-0 items-center gap-2.5', 'cursor-pointer' => $item['selectable'], 'opacity-60' => ! $item['selectable']])>
                                        <input type="checkbox" value="{{ $item['key'] }}" wire:model.live="selected" class="size-4 shrink-0 rounded border-line-strong accent-[var(--local)]" @disabled(! $item['selectable'])>
                                        <span class="min-w-0 truncate">{{ $item['name'] }} <span class="text-faint">{{ $item['type'] }}</span></span>
                                    </label>
                                    <span class="shrink-0 font-mono text-[0.8125rem] text-muted">@if ($item['from']){{ $item['from'] }} @endif→ {{ $item['to'] }}</span>
                                </li>
                                @if ($item['key'] === 'core' && ! $item['selectable'])
                                    <li class="px-3 py-2 text-[0.8125rem] text-faint">{{ __('WordPress zelf kan bij een site met alleen SFTP niet zo bijgewerkt worden; doe dat via wp-admin of de hosting.') }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </div>
                @error('selected')<p class="text-[0.8125rem] text-danger">{{ $message }}</p>@enderror
                @if ($items->isNotEmpty() && $this->isStale())
                    <p class="flex items-start gap-2 text-[0.8125rem] text-muted"><x-icon name="clock" :size="14" class="mt-0.5 text-faint" />{{ __('Deze lijst is :time gecontroleerd; tijdens het bijwerken wordt op live opnieuw gekeken wat er nog openstaat.', ['time' => $site->updates_checked_at?->diffForHumans() ?? __('nog niet')]) }}</p>
                @endif
            </div>

            <div class="rounded-lg border border-line p-3">
                <x-toggle wire:model.live="live" :label="__('Daarna live zetten')" :description="$live ? __('Na een geslaagde controle gaan de updates naar live, met eerst een back-up van de live-bestanden.') : __('Alleen lokaal testen: live blijft zoals hij is. Je kunt de bijgewerkte site daarna lokaal bekijken.')" />
            </div>

            <p class="flex items-start gap-2 text-[0.8125rem] text-muted"><x-icon name="shield" :size="14" class="mt-0.5 text-success" />{{ __('Lukt een stap niet, dan wordt alles teruggezet en blijft live ongemoeid.') }}</p>
        </div>

        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button :variant="$live ? 'live' : 'primary'" :icon="$live ? 'shield' : 'check'" wire:click="start" :disabled="$selected === []">
                {{ $live ? __('Bijwerken en live zetten') : __('Alleen lokaal testen') }}
            </x-button>
        </x-slot:footer>
    </x-modal>
</div>
