<div class="grid gap-6" @if ($this->checking->isNotEmpty()) wire:poll.2s="poll" @else wire:poll.60s="poll" @endif>
    <x-page-header :title="__('Uptime & onderhoud')" :description="__('Is alles online en bijgewerkt? Hier zie je per live-site de uptime, beschikbare updates en de laatste back-up.')">
        <x-slot:actions>
            <x-button icon="refresh" wire:click="checkAll" :disabled="$this->checking->isNotEmpty()">
                {{ $this->checking->isNotEmpty() ? trans_choice(':count site wordt gecontroleerd|:count sites worden gecontroleerd', $this->checking->count()) : __('Alle updates controleren') }}
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->sites->isEmpty())
        <x-empty-state icon="shield" :title="__('Nog geen live-sites')" :description="__('Zodra je een site met een live-server koppelt, verschijnt hij hier met zijn status.')">
            <x-button variant="primary" icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Site toevoegen') }}</x-button>
        </x-empty-state>
    @else
        @php
            $summary = $this->summary;
            $tiles = [
                ['label' => __('Offline'), 'value' => $summary['offline'], 'bad' => $summary['offline'] > 0],
                ['label' => __('Updates beschikbaar'), 'value' => $summary['updates'], 'bad' => false, 'warn' => $summary['updates'] > 0],
                ['label' => __('Back-up ouder dan :days dagen', ['days' => \App\Livewire\Maintenance\Index::BACKUP_WARNING_DAYS]), 'value' => $summary['backups'], 'bad' => false, 'warn' => $summary['backups'] > 0],
            ];
        @endphp

        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            @foreach ($tiles as $tile)
                <div @class([
                    'rounded-xl border px-4 py-3',
                    'border-danger/30 bg-danger-soft' => $tile['bad'],
                    'border-warning/30 bg-warning-soft' => ($tile['warn'] ?? false),
                    'border-line bg-surface' => ! $tile['bad'] && ! ($tile['warn'] ?? false),
                ])>
                    <dt class="text-xs text-muted">{{ $tile['label'] }}</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums">{{ $tile['value'] }}</dd>
                </div>
            @endforeach
        </dl>

        <x-panel :padding="false">
            <div class="hidden grid-cols-[minmax(0,1.15fr)_minmax(0,1.15fr)_minmax(0,1.5fr)_minmax(0,1fr)_auto] gap-4 border-b border-line px-4 py-2 text-xs font-medium text-faint lg:grid">
                <span>{{ __('Site') }}</span><span>{{ __('Uptime (30 dagen)') }}</span><span>{{ __('Updates') }}</span><span>{{ __('Laatste back-up') }}</span><span class="w-32"></span>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($this->sites as $site)
                    @php
                        $backup = $site->lastBackupAt();
                        $updates = $site->updatesCount();
                        $isChecking = $this->checking->contains($site->id);
                        $canCheck = $site->supportsUpdateCheck();
                    @endphp
                    <li wire:key="maintenance-{{ $site->id }}" class="grid items-center gap-x-4 gap-y-2 px-4 py-3 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1.15fr)_minmax(0,1.5fr)_minmax(0,1fr)_auto]">
                        <div class="min-w-0">
                            <a href="{{ route('sites.show', $site) }}" wire:navigate class="block truncate text-[0.9375rem] font-semibold hover:text-local-ink">{{ $site->name }}</a>
                            <p class="truncate font-mono text-xs text-faint">{{ $site->data('live_host') ?: $site->data('target') }}</p>
                        </div>

                        <div>
                            @if ($site->isMonitored())
                                <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'uptime']) }}" wire:navigate class="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5" title="{{ __('Uptime bekijken') }}">
                                    <x-badge :tone="$site->uptime->tone()" dot>{{ $site->uptime->label() }}</x-badge>
                                    @if (($uptime = $site->uptimePercentage()) !== null)
                                        <span class="text-[0.8125rem] tabular-nums text-muted">{{ \Illuminate\Support\Number::format($uptime, maxPrecision: 2) }}%</span>
                                    @endif
                                </a>
                            @else
                                <x-badge>{{ __('Niet bewaakt') }}</x-badge>
                            @endif
                        </div>

                        <div class="text-[0.8125rem]">
                            @if ($isChecking)
                                <span class="inline-flex items-center gap-1.5 text-live-ink"><x-icon name="loader" :size="13" class="animate-spin" />{{ __('Controleren…') }}</span>
                            @elseif ($site->updates_checked_at === null)
                                <span class="text-faint">{{ $canCheck ? __('Nog niet gecontroleerd') : __('Eerst lokaal bouwen') }}</span>
                            @else
                                @if ($updates > 0)
                                    <x-badge tone="warning">{{ trans_choice(':count update|:count updates', $updates) }}</x-badge>
                                @else
                                    <x-badge tone="success">{{ __('Bijgewerkt') }}</x-badge>
                                @endif
                                <span class="ml-1 whitespace-nowrap text-faint">{{ $site->updates_checked_at->diffForHumans(short: true) }}</span>
                            @endif
                        </div>

                        <div class="text-[0.8125rem]">
                            @if ($backup === null)
                                <x-badge tone="warning">{{ __('Geen back-up') }}</x-badge>
                            @else
                                <span @class(['text-warning' => $this->backupIsStale($site), 'text-muted' => ! $this->backupIsStale($site)])>{{ $backup->diffForHumans() }}</span>
                            @endif
                        </div>

                        <div class="flex w-32 justify-end gap-1">
                            <x-button size="sm" icon="refresh" wire:click="runQuick({{ $site->id }}, 'updates')" :disabled="! $canCheck || $isChecking" :title="__('Updates van deze site controleren')">{{ __('Controleren') }}</x-button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-panel>

        <p class="text-[0.8125rem] text-faint">{{ __('Uptime wordt op de achtergrond gemeten, updates dagelijks. Beide lopen alleen zolang BD Deck open staat; zie Instellingen.') }}</p>
    @endif
</div>
