@php
    /** @var \App\Models\Site $site */
    $active = $this->activeRun;
    $git = $site->data('git');
    $tabs = ['overzicht' => __('Overzicht'), 'back-ups' => __('Back-ups'), 'wp-cli' => __('WP-CLI'), 'logboek' => __('Logboek')];
    $chip = 'inline-flex h-7 items-center gap-1.5 rounded-md bg-surface/70 px-2 text-[0.8125rem] font-medium transition-colors hover:bg-surface';
@endphp

<div class="grid gap-6" @if ($active) wire:poll.1500ms="poll" @endif>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="grid gap-1.5">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex w-fit items-center gap-1 text-[0.8125rem] text-muted hover:text-ink">
                <x-icon name="arrow-left" :size="14" />{{ __('Alle sites') }}
            </a>
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-[1.5rem] font-semibold tracking-[-0.015em]">{{ $site->name }}</h1>
                <button type="button" wire:click="toggleFavorite({{ $site->id }})" @class(['grid size-8 place-items-center rounded-lg hover:bg-raised', 'text-warning' => $site->is_favorite, 'text-faint' => ! $site->is_favorite]) title="{{ __('Favoriet') }}">
                    <x-icon name="star" :class="$site->is_favorite ? 'fill-current' : ''" />
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-[0.8125rem] text-muted">
                <x-provider-badge :provider="$site->providerType" />
                <x-badge>{{ $site->mode->label() }}</x-badge>
                <span class="font-mono text-xs">{{ $site->data('target') }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($site->providerType->dashboardUrl())
                <x-button icon="external" wire:click="launch({{ $site->id }}, 'hosting')">{{ $site->providerType->label() }}</x-button>
            @endif
            <x-button icon="pencil" :href="route('sites.edit', $site)" wire:navigate>{{ __('Gegevens') }}</x-button>
        </div>
    </header>

    {{-- de brug: lokaal links, live rechts, sync in het midden --}}
    <x-site-bridge :site="$site" size="lg" :flow="$this->flow()">
        <x-slot:local>
            @if ($site->isBuilt())
                <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'local-admin')"><x-icon name="wordpress" :size="14" />{{ __('WP-admin') }}</button>
                <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'local-site')"><x-icon name="globe" :size="14" />{{ __('Website') }}</button>
                <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'editor')"><x-icon name="code" :size="14" />{{ __('Editor') }}</button>
                <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'terminal')"><x-icon name="terminal" :size="14" />{{ __('Terminal') }}</button>
                <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'folder')"><x-icon name="folder" :size="14" />{{ __('Map') }}</button>
            @else
                <x-button size="sm" variant="primary" icon="hammer" wire:click="run('build')" :disabled="(bool) $active">{{ __('Lokaal bouwen') }}</x-button>
            @endif
        </x-slot:local>

        <x-slot:actions>
            @if ($site->isBuilt())
                <div x-data="{ open: false }" class="relative">
                    <x-button size="sm" icon="arrow-left" x-on:click="open = ! open" :disabled="(bool) $active">{{ __('Ophalen') }}</x-button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity.duration.100ms class="absolute left-1/2 top-full z-20 mt-1.5 w-60 -translate-x-1/2 rounded-xl border border-line bg-surface p-1 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.4)]">
                        <button type="button" class="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left hover:bg-raised" wire:click="run('pull-code')" x-on:click="open = false">
                            <x-icon name="code" class="mt-0.5 text-local-ink" />
                            <span><span class="block text-sm font-medium">{{ __('Code') }}</span><span class="block text-[0.8125rem] text-muted">{{ __('Thema en plugins van live naar main en dev.') }}</span></span>
                        </button>
                        <button type="button" class="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left hover:bg-raised" wire:click="confirm('pull-database')" x-on:click="open = false">
                            <x-icon name="database" class="mt-0.5 text-local-ink" />
                            <span><span class="block text-sm font-medium">{{ __('Database') }}</span><span class="block text-[0.8125rem] text-muted">{{ __('Verse kopie van live, lokale db wordt eerst bewaard.') }}</span></span>
                        </button>
                    </div>
                </div>
                <x-button size="sm" variant="live" iconRight="arrow-right" x-on:click="$dispatch('open-modal', 'push'); $dispatch('open-push')" :disabled="(bool) $active">{{ __('Naar live') }}</x-button>
            @endif
        </x-slot:actions>

        <x-slot:live>
            <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'live-admin')"><x-icon name="wordpress" :size="14" />{{ __('WP-admin') }}</button>
            <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'live-site')"><x-icon name="globe" :size="14" />{{ __('Website') }}</button>
            <button type="button" class="{{ $chip }}" wire:click="launch({{ $site->id }}, 'ssh')"><x-icon name="server" :size="14" />{{ $site->mode->hasShell() ? 'SSH' : 'SFTP' }}</button>
        </x-slot:live>
    </x-site-bridge>

    @if ($active)
        <x-run-progress :run="$active" />
    @elseif ($this->lastRun?->status === \App\Enums\RunStatus::Failed)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3">
            <x-icon name="alert" class="text-danger" />
            <p class="min-w-0 flex-1 text-sm"><span class="font-medium">{{ __(':action is mislukt.', ['action' => $this->lastRun->label]) }}</span> <span class="text-muted">{{ $this->lastRun->lastMessage() }}</span></p>
            <x-button size="sm" :href="route('activity.show', $this->lastRun)" wire:navigate>{{ __('Log bekijken') }}</x-button>
        </div>
    @endif

    <nav class="flex gap-1 border-b border-line" aria-label="{{ __('Onderdelen') }}">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                '-mb-px border-b-2 px-3 pb-2.5 text-sm transition-colors',
                'border-local font-medium text-ink' => $tab === $key,
                'border-transparent text-muted hover:text-ink' => $tab !== $key,
            ]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overzicht')
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
            <div class="grid content-start gap-5">
                <x-panel :title="__('Versiebeheer')" :description="__('main is de laatst bekende live-staat, dev is jouw werk.')">
                    @if ($git)
                        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2.5 text-sm">
                            <dt class="text-muted">{{ __('Branch') }}</dt>
                            <dd class="flex items-center gap-1.5 font-mono text-[0.8125rem]"><x-icon name="git-branch" :size="14" class="text-faint" />{{ $git['branch'] ?: '–' }}</dd>
                            <dt class="text-muted">{{ __('Klaar voor live') }}</dt>
                            <dd>
                                @if (($git['pending_files'] ?? 0) + ($git['dirty'] ?? 0) === 0)
                                    {{ __('Niets, lokaal is gelijk aan live') }}
                                @else
                                    {{ trans_choice(':count gecommit bestand|:count gecommitte bestanden', $git['pending_files'] ?? 0) }}@if ($git['dirty'] ?? 0), <span class="text-warning">{{ trans_choice(':count nog niet gecommit|:count nog niet gecommit', $git['dirty']) }}</span>@endif
                                @endif
                            </dd>
                            <dt class="text-muted">{{ __('Laatste commit') }}</dt>
                            <dd class="min-w-0">
                                @if ($git['last_commit'] ?? null)
                                    <span class="truncate">{{ $git['last_commit']['subject'] }}</span>
                                    <span class="text-faint"> {{ \Illuminate\Support\Carbon::parse($git['last_commit']['date'])->diffForHumans() }}</span>
                                @else – @endif
                            </dd>
                            <dt class="text-muted">{{ __('Laatst live gezet') }}</dt>
                            <dd>{{ ($git['last_deploy'] ?? null) ? \Illuminate\Support\Carbon::parse($git['last_deploy'])->diffForHumans() : __('Nog niet via BD Deck') }}</dd>
                            <dt class="text-muted">{{ __('GitHub') }}</dt>
                            <dd class="truncate font-mono text-[0.8125rem]">{{ $git['remote'] ?? __('Geen remote') }}</dd>
                        </dl>
                    @else
                        <p class="text-muted">{{ __('Bouw de site lokaal om versiebeheer te starten. BD Deck houdt dan je eigen thema en plugins bij in git.') }}</p>
                    @endif
                </x-panel>

                <x-panel :title="__('Herstellen')" :description="__('Als er lokaal iets mis is gegaan. Deze acties raken live niet.')" :padding="false">
                    <ul class="divide-y divide-line">
                        @php
                            $repairs = [
                                ['action' => 'fix-plugins', 'icon' => 'wrench', 'label' => __('Site repareren'), 'text' => __('Prefix, URL\'s, uploads en kapotte plugins rechtzetten.')],
                                ['action' => 'pull-database', 'icon' => 'database', 'label' => __('Database opnieuw ophalen'), 'text' => __('Verse database van live, lokaal eerst bewaard.')],
                                ['action' => 'reset', 'icon' => 'rotate-ccw', 'label' => __('Terugzetten naar main'), 'text' => __('Weg met lokale experimenten; werk blijft in een backup-branch.')],
                                ['action' => 'rebuild', 'icon' => 'hammer', 'label' => __('Opnieuw migreren'), 'text' => __('Alles lokaal opnieuw opbouwen vanaf live.')],
                            ];
                        @endphp
                        @foreach ($repairs as $repair)
                            <li class="flex items-center gap-3 px-5 py-3">
                                <x-icon :name="$repair['icon']" class="text-faint" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">{{ $repair['label'] }}</p>
                                    <p class="text-[0.8125rem] text-muted">{{ $repair['text'] }}</p>
                                </div>
                                <x-button size="sm" wire:click="confirm('{{ $repair['action'] }}')" :disabled="(bool) $active || (! $site->isBuilt() && $repair['action'] !== 'rebuild')">{{ __('Uitvoeren') }}</x-button>
                            </li>
                        @endforeach
                        @if ($site->isBuilt())
                            <li class="flex items-center gap-3 px-5 py-3">
                                <x-icon :name="$site->isRunning() ? 'square' : 'play'" class="text-faint" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">{{ $site->isRunning() ? __('Lokale site stoppen') : __('Lokale site starten') }}</p>
                                    <p class="text-[0.8125rem] text-muted">{{ __('DDEV-containers van deze site.') }}</p>
                                </div>
                                <x-button size="sm" wire:click="run('{{ $site->isRunning() ? 'stop' : 'start' }}')" :disabled="(bool) $active">{{ $site->isRunning() ? __('Stoppen') : __('Starten') }}</x-button>
                            </li>
                        @endif
                    </ul>
                </x-panel>
            </div>

            <div class="grid content-start gap-5">
                <x-panel :title="__('Verbinding')" :description="$site->health_checked_at ? __('Getest :time', ['time' => $site->health_checked_at->diffForHumans()]) : __('Nog niet getest')">
                    <x-slot:actions>
                        <x-button size="sm" icon="activity" wire:click="run('test')">{{ __('Testen') }}</x-button>
                    </x-slot:actions>
                    @if ($site->health)
                        <ul class="grid gap-2.5">
                            @foreach ($site->health as $check)
                                <li class="flex items-start gap-2.5">
                                    <span @class([
                                        'mt-1 grid size-4 shrink-0 place-items-center rounded-full',
                                        'bg-success-soft text-success' => $check['status'] === 'ok',
                                        'bg-danger-soft text-danger' => $check['status'] === 'fail',
                                        'bg-raised text-faint' => $check['status'] === 'skip',
                                    ])>
                                        <x-icon :name="match ($check['status']) { 'ok' => 'check', 'fail' => 'x', default => 'more' }" :size="10" />
                                    </span>
                                    <span class="grid leading-snug">
                                        <span class="text-sm font-medium">{{ $check['label'] }}</span>
                                        <span class="text-[0.8125rem] text-muted">{{ $check['detail'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">{{ __('Controleert of SSH/SFTP werkt, WP-CLI op de server draait en de site online is.') }}</p>
                    @endif
                </x-panel>

                <x-panel :title="__('Updates op live')" :description="$site->updates_checked_at ? __('Gecontroleerd :time', ['time' => $site->updates_checked_at->diffForHumans()]) : __('Nog niet gecontroleerd')">
                    <x-slot:actions>
                        <x-button size="sm" icon="refresh" wire:click="run('updates')">{{ __('Controleren') }}</x-button>
                    </x-slot:actions>
                    @if ($site->updates === null)
                        <p class="text-muted">{{ __('Bekijk welke plugins, thema\'s en WordPress-versies een update hebben.') }}</p>
                    @elseif ($site->updatesCount() === 0)
                        <p class="flex items-center gap-2 text-success"><x-icon name="check" />{{ __('Alles is up-to-date') }}</p>
                    @else
                        <ul class="grid gap-2 text-sm">
                            @foreach ($site->updates['core'] as $core)
                                <li class="flex justify-between gap-3"><span class="font-medium">WordPress</span><span class="font-mono text-[0.8125rem] text-muted">→ {{ $core['version'] }}</span></li>
                            @endforeach
                            @foreach (['plugins' => __('plugin'), 'themes' => __('thema')] as $type => $typeLabel)
                                @foreach ($site->updates[$type] as $item)
                                    <li class="flex justify-between gap-3">
                                        <span class="min-w-0 truncate">{{ $item['title'] ?: $item['name'] }} <span class="text-faint">{{ $typeLabel }}</span></span>
                                        <span class="shrink-0 font-mono text-[0.8125rem] text-muted">{{ $item['version'] }} → {{ $item['update_version'] }}</span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                </x-panel>

                <x-panel :title="__('Cache')">
                    <div class="flex flex-wrap gap-2">
                        <x-button size="sm" icon="zap" wire:click="run('cache-local')" :disabled="! $site->isBuilt()">{{ __('Lokaal legen') }}</x-button>
                        <x-button size="sm" icon="zap" wire:click="confirm('cache-live')">{{ __('Live legen') }}</x-button>
                    </div>
                </x-panel>

                <x-panel :title="__('Notities')">
                    <x-textarea wire:model.blur="notes" wire:change="saveNotes" rows="4" :placeholder="__('Bijvoorbeeld: klant wil geen automatische plugin-updates.')" />
                </x-panel>
            </div>
        </div>
    @elseif ($tab === 'back-ups')
        <livewire:sites.backups :site="$site" :key="'backups-'.$site->id" />
    @elseif ($tab === 'wp-cli')
        <livewire:sites.console :site="$site" :key="'console-'.$site->id" />
    @else
        <x-panel :padding="false">
            @if ($this->runs->isEmpty())
                <div class="p-5"><x-empty-state icon="history" :title="__('Nog geen activiteit')" :description="__('Alles wat je met deze site doet, verschijnt hier met de volledige uitvoer.')" /></div>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->runs as $run)
                        <li wire:key="run-{{ $run->id }}"><x-run-row :run="$run" :show-site="false" /></li>
                    @endforeach
                </ul>
                <div class="border-t border-line px-5 py-3">{{ $this->runs->links() }}</div>
            @endif
        </x-panel>
    @endif

    <livewire:sites.push-panel :site="$site" :key="'push-'.$site->id" />

    @php $confirmation = $confirming ? $this->confirmations()[$confirming] : null; @endphp
    <x-modal name="confirm-action" :title="$confirmation['title'] ?? ''" :tone="($confirmation['danger'] ?? false) ? 'danger' : null">
        @if ($confirmation)
            <div class="grid gap-4">
                <p class="text-muted">{{ $confirmation['body'] }}</p>
                @if ($confirming === 'reset')
                    <x-toggle wire:model="pullBeforeReset" :label="__('Daarna live ophalen')" :description="__('Haalt eerst de nieuwste code van live op, zodat main echt actueel is.')" />
                @endif
                @if ($confirmation['typed'])
                    <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="confirm-text" error="confirmText">
                        <x-input id="confirm-text" wire:model="confirmText" autocomplete="off" mono />
                    </x-field>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button :variant="($confirmation['danger'] ?? false) ? 'danger' : 'primary'" wire:click="proceed">{{ $confirmation['button'] ?? __('Doorgaan') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
