@php
    /** @var \App\Models\Site $site */
    $busy = $site->runs()->active()->exists();
    $git = $site->data('git');
    $pending = $site->pendingChanges();
    $push = $site->lastPush();
    $pushedTheme = $push ? collect($site->shopifyThemes())->firstWhere('id', (int) $push['theme_id']) : null;
    $publish = $site->lastPublish();
    $themes = $site->shopifyThemes();
    $check = $site->shopifyCheck();
    $roleTone = ['live' => 'live', 'unpublished' => 'neutral', 'development' => 'info', 'demo' => 'neutral'];
    $roleLabel = ['live' => __('Live'), 'unpublished' => __('Preview'), 'development' => __('Dev'), 'demo' => __('Demo')];
@endphp

<div class="grid gap-5">
    <x-panel :title="__('Live zetten')" :description="__('Nooit rechtstreeks: eerst naar een niet-gepubliceerd thema, daar kijk je rustig naar, en pas dan zet je het bewust live.')">
        <div class="grid gap-5">
            <ol class="grid gap-3">
                {{-- 1. ophalen --}}
                <li class="flex flex-wrap items-center gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-local-soft text-xs font-semibold text-local-ink">1</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ __('Wat de klant deed ophalen') }}</p>
                        <p class="text-[0.8125rem] text-muted">{{ __('Haalt de bestanden op die klanten in de theme editor aanpassen (:files), zodat jouw map niet achterloopt.', ['files' => implode(', ', $site->protectedPatterns()) ?: __('geen ingesteld')]) }}</p>
                    </div>
                    <x-button size="sm" icon="download" wire:click="pullFiles(false)" :disabled="$busy">{{ __('Editor-wijzigingen ophalen') }}</x-button>
                    <x-button size="sm" variant="ghost" x-on:click="$dispatch('open-modal', 'shopify-pull-all')" :disabled="$busy">{{ __('Hele thema…') }}</x-button>
                </li>

                {{-- 2. controleren --}}
                <li class="flex flex-wrap items-center gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-local-soft text-xs font-semibold text-local-ink">2</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ __('Thema controleren') }}</p>
                        <p class="text-[0.8125rem] text-muted">
                            @if ($check)
                                {{ __(':errors fouten, :warnings waarschuwingen', ['errors' => $check['errors'], 'warnings' => $check['warnings']]) }} · {{ $site->shopifyCheckedAt()?->diffForHumans() }}
                            @else
                                {{ __('shopify theme check; wordt ook automatisch uitgevoerd vóór elke push.') }}
                            @endif
                        </p>
                    </div>
                    <x-button size="sm" icon="shield" wire:click="runQuick({{ $site->id }}, 'shopify-check')" :disabled="$busy" title="{{ __('Sneltoets: c') }}">{{ __('Controleren') }}</x-button>
                </li>

                {{-- 3. pushen --}}
                <li class="flex flex-wrap items-center gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-local-soft text-xs font-semibold text-local-ink">3</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ __('Naar een preview-thema pushen') }}</p>
                        <p class="text-[0.8125rem] text-muted">
                            @if ($git)
                                {{ $pending === 0 ? __('Niets nieuws sinds de laatste keer live.') : trans_choice(':count wijziging klaar|:count wijzigingen klaar', $pending) }}
                            @endif
                            {{ __('De live winkel verandert niet.') }}
                        </p>
                    </div>
                    <x-button size="sm" variant="primary" icon="upload" wire:click="openPush" :disabled="$busy">{{ __('Pushen naar preview') }}</x-button>
                </li>

                {{-- 4 + 5. preview en publiceren --}}
                <li class="flex flex-wrap items-center gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-live-soft text-xs font-semibold text-live-ink">4</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ __('Preview bekijken en publiceren') }}</p>
                        @if ($push)
                            <p class="text-[0.8125rem] text-muted">{{ __('Laatst gepusht: :name, :time.', ['name' => $push['theme_name'], 'time' => \Illuminate\Support\Carbon::parse($push['at'])->diffForHumans()]) }}</p>
                        @else
                            <p class="text-[0.8125rem] text-muted">{{ __('Nog niet gepusht via BD Deck.') }}</p>
                        @endif
                    </div>
                    @if ($push)
                        <a href="{{ $push['preview_url'] }}" target="_blank" rel="noopener" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 text-[0.8125rem] font-medium hover:border-line-strong"><x-icon name="eye" :size="14" />{{ __('Preview') }}</a>
                        <x-copy-button :text="$push['preview_url']" :label="__('Link')" />
                        <x-button size="sm" variant="live" icon="rocket" wire:click="openPublish({{ (int) $push['theme_id'] }})" :disabled="$busy || ($pushedTheme['role'] ?? null) !== 'unpublished'">{{ __('Publiceren…') }}</x-button>
                    @endif
                </li>
            </ol>

            @if ($this->checkBlockedRun)
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-warning/30 bg-warning-soft px-3 py-2.5 text-[0.8125rem]">
                    <x-icon name="alert" class="text-warning" />
                    <p class="min-w-0 flex-1"><span class="font-medium">{{ __('De push is tegengehouden door nieuwe fouten in de themacontrole.') }}</span> {{ __('Los ze op, of ga bewust toch door: de fouten die er al waren worden dan onthouden.') }}</p>
                    <x-button size="sm" wire:click="pushAcceptingErrors" :disabled="$busy">{{ __('Toch pushen') }}</x-button>
                </div>
            @endif

            @if ($publish && $site->canRollbackPublish())
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-success/30 bg-success-soft px-3 py-2.5 text-[0.8125rem]">
                    <x-icon name="check" class="text-success" />
                    <p class="min-w-0 flex-1"><span class="font-medium">{{ __(':name is live gezet', ['name' => $publish['new_name']]) }}</span> <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($publish['at'])->diffForHumans() }} · {{ __('vorige live: :name', ['name' => $publish['previous_name']]) }}</span></p>
                    <x-button size="sm" variant="danger-ghost" icon="rotate-ccw" x-on:click="$dispatch('open-modal', 'shopify-rollback')" :disabled="$busy">{{ __('Terugdraaien') }}</x-button>
                </div>
            @endif
        </div>
    </x-panel>

    <x-panel :title="__('Thema\'s in de winkel')" :description="$site->shopifyThemesCheckedAt() ? __('Opgehaald :time', ['time' => $site->shopifyThemesCheckedAt()->diffForHumans()]) : __('Nog niet opgehaald. Dit is ook de manier om in te loggen bij Shopify.')">
        <x-slot:actions>
            <x-button size="sm" icon="refresh" wire:click="runQuick({{ $site->id }}, 'shopify-themes')" :disabled="$busy">{{ __('Ophalen') }}</x-button>
        </x-slot:actions>
        @if ($themes === [])
            <p class="text-muted">{{ __('Haal de themalijst op om te zien welk thema live staat en welke previews er zijn.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($themes as $theme)
                    <li class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0" wire:key="theme-{{ $theme['id'] }}">
                        <x-badge :tone="$roleTone[$theme['role']] ?? 'neutral'" class="w-20 justify-center">{{ $roleLabel[$theme['role']] ?? $theme['role'] }}</x-badge>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $theme['name'] }} <span class="font-mono text-xs font-normal text-faint">{{ $theme['id'] }}</span></span>
                        <a href="{{ $theme['preview_url'] }}" target="_blank" rel="noopener" class="text-[0.8125rem] text-muted hover:text-ink">{{ __('Preview') }}</a>
                        <a href="{{ $theme['editor_url'] }}" target="_blank" rel="noopener" class="text-[0.8125rem] text-muted hover:text-ink">{{ __('Editor') }}</a>
                        @if ($theme['role'] === 'unpublished')
                            <x-button size="sm" variant="live" icon="rocket" wire:click="openPublish({{ $theme['id'] }})" :disabled="$busy">{{ __('Publiceren…') }}</x-button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    @if ($check && ($check['offenses'] ?? []) !== [])
        <x-panel :title="__('Resultaat themacontrole')" :description="__(':errors fouten, :warnings waarschuwingen, :info tips', ['errors' => $check['errors'], 'warnings' => $check['warnings'], 'info' => $check['info']])">
            <ul class="grid max-h-72 gap-1.5 overflow-auto text-[0.8125rem]">
                @foreach (collect($check['offenses'])->sortBy(fn (array $o): int => ['error' => 0, 'warning' => 1][$o['severity']] ?? 2)->take(60) as $offense)
                    <li class="flex items-start gap-2" wire:key="offense-{{ $loop->index }}">
                        <x-badge :tone="['error' => 'danger', 'warning' => 'warning'][$offense['severity']] ?? 'neutral'" class="mt-px shrink-0">{{ ['error' => __('fout'), 'warning' => __('waarschuwing')][$offense['severity']] ?? __('tip') }}</x-badge>
                        <span class="min-w-0"><span class="font-mono text-xs">{{ $offense['file'] }}:{{ $offense['line'] }}</span> <span class="text-faint">{{ $offense['check'] }}</span><br><span class="text-muted">{{ $offense['message'] }}</span></span>
                    </li>
                @endforeach
            </ul>
        </x-panel>
    @endif

    {{-- pushen --}}
    <x-modal name="shopify-push" :title="__('Naar een preview-thema pushen')" width="max-w-xl">
        <div class="grid gap-4">
            <div class="rounded-lg border border-live/30 bg-live-soft/60 px-3 py-2.5 text-[0.8125rem]">
                <p class="font-medium">{{ __('Winkel: :store', ['store' => $site->shopifyStore()]) }}</p>
                <p class="text-muted">{{ __('Het live thema blijft onaangeroerd. :patterns blijven in een bestaand preview-thema staan.', ['patterns' => implode(', ', $site->protectedPatterns()) ?: __('Er zijn geen beschermde bestanden ingesteld, dus alles')]) }}</p>
            </div>
            <x-field :label="__('Naam van het preview-thema')" for="push-name" error="pushName" :hint="__('Een bestaande naam werkt dat thema bij; een nieuwe naam maakt een nieuw niet-gepubliceerd thema. Leeg: een naam met de datum.')">
                <x-input id="push-name" wire:model="pushName" list="preview-themes" maxlength="60" :placeholder="__('Bijvoorbeeld: Zomeractie')" />
                <datalist id="preview-themes">
                    @foreach ($this->previewThemes as $theme)
                        <option value="{{ $theme['name'] }}"></option>
                    @endforeach
                </datalist>
            </x-field>
            <x-toggle wire:model="pushPull" :label="__('Eerst ophalen wat de klant deed')" :description="__('Aanbevolen. Bij een nieuw thema gebeurt dit altijd, anders mist het thema de pagina-instellingen van de klant.')" />
            <x-toggle wire:model="pushCheck" :label="__('Thema controleren')" :description="__('Nieuwe fouten houden de push tegen; fouten die er al waren niet.')" />
        </div>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="primary" icon="upload" wire:click="push">{{ __('Pushen') }}</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- hele thema ophalen --}}
    <x-modal name="shopify-pull-all" :title="__('Het hele live thema ophalen')">
        <p class="text-muted">{{ __('Alle bestanden van het live thema komen in je projectmap en worden als commit vastgelegd. Heb je lokaal nog niet-vastgelegd werk, dan stopt BD Deck voordat er iets overschreven wordt. Live verandert niet.') }}</p>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="primary" icon="download" wire:click="pullFiles(true)">{{ __('Ophalen') }}</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- publiceren: dit raakt de live winkel --}}
    @php $theme = $this->publishTheme; $live = $this->liveTheme; @endphp
    <x-modal name="shopify-publish" :title="__('Thema live zetten')" tone="danger" width="max-w-xl">
        @if ($theme)
            <div class="grid gap-4">
                <dl class="grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 rounded-lg border border-danger/30 bg-danger-soft px-3 py-3 text-sm">
                    <dt class="text-muted">{{ __('Winkel') }}</dt>
                    <dd class="font-mono text-[0.8125rem] font-semibold">{{ $site->shopifyStore() }}</dd>
                    <dt class="text-muted">{{ __('Nu live') }}</dt>
                    <dd>{{ $live['name'] ?? __('onbekend (haal de themalijst op)') }} @if ($live)<span class="font-mono text-xs text-faint">{{ $live['id'] }}</span>@endif</dd>
                    <dt class="text-muted">{{ __('Wordt live') }}</dt>
                    <dd class="font-semibold">{{ $theme['name'] }} <span class="font-mono text-xs font-normal text-faint">{{ $theme['id'] }}</span></dd>
                </dl>
                <p class="text-[0.8125rem] text-muted">{{ __('Bezoekers zien meteen het nieuwe thema. Eerst maakt BD Deck een back-up van het huidige live thema en een git-tag van je werk; het oude thema blijft in de winkel staan, dus terugdraaien kan met één klik.') }}</p>
                <x-toggle wire:model="publishSyncEditorFiles" :label="__('Wijzigingen van de klant overnemen')" :description="__('Aanbevolen. Heeft de klant sinds de preview iets in de editor aangepast (:files)? Dan neemt BD Deck die live-versie over in het preview-thema, zodat er niets verloren gaat. Uit: het preview-thema wint en die wijzigingen gaan verloren (ze staan wel in de back-up).', ['files' => implode(', ', $site->protectedPatterns())])" />
                <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="publish-confirm" error="confirmName">
                    <x-input id="publish-confirm" wire:model="confirmName" autocomplete="off" mono />
                </x-field>
            </div>
        @endif
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="danger" icon="rocket" wire:click="publish">{{ __('Live zetten') }}</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- terugdraaien --}}
    <x-modal name="shopify-rollback" :title="__('Publicatie terugdraaien')" tone="danger">
        @if ($publish)
            <div class="grid gap-3">
                <p class="text-muted">{{ __('In :store wordt :previous weer het live thema; :current wordt een niet-gepubliceerd thema. Wat de klant sinds de publicatie in de editor deed, zit in :current en niet in :previous.', ['store' => $site->shopifyStore(), 'previous' => $publish['previous_name'], 'current' => $publish['new_name']]) }}</p>
            </div>
        @endif
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="danger" icon="rotate-ccw" wire:click="rollback">{{ __('Terugdraaien') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
