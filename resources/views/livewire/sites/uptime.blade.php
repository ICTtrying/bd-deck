@php
    $uptime = $site->uptime;
    $stats = [
        __('24 uur') => $site->uptimePercentageSince(1),
        __('7 dagen') => $site->uptimePercentageSince(7),
        __('30 dagen') => $site->uptimePercentageSince(30),
    ];
@endphp

<div class="grid gap-5" wire:poll.30s>
    <x-panel :title="__('Uptime')" :description="$globallyEnabled ? __('Elke :interval wordt de live-website opgehaald. Pas na twee missers op rij volgt een storingsmelding.', ['interval' => trans_choice(':count minuut|:count minuten', $interval)]) : __('Bewaking staat uit voor alle sites. Zet hem aan bij Instellingen.')">
        <x-slot:actions>
            <x-button size="sm" icon="refresh" wire:click="check" :disabled="! $site->hasLiveUrl()">{{ __('Nu meten') }}</x-button>
        </x-slot:actions>

        @unless ($site->hasLiveUrl())
            <p class="text-muted">{{ __('Deze site heeft nog geen live-adres om te meten.') }}</p>
        @else
            <div class="grid gap-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        @if ($site->uptime_enabled)
                            <x-badge :tone="$uptime->tone()" dot>{{ $uptime->label() }}</x-badge>
                            @if ($site->uptime_status_since && $uptime !== \App\Enums\UptimeStatus::Unknown)
                                <span class="text-[0.8125rem] text-muted">{{ __('sinds :time', ['time' => $site->uptime_status_since->diffForHumans()]) }}</span>
                            @endif
                        @else
                            <x-badge>{{ __('Bewaking uit') }}</x-badge>
                        @endif
                        <span class="font-mono text-xs text-faint">{{ $site->liveUrl('/') }}</span>
                    </div>
                    <x-toggle wire:click="toggle" :checked="$site->uptime_enabled" :label="__('Bewaken')" />
                </div>

                @if ($site->uptime_enabled)
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ($stats as $label => $value)
                            <div class="rounded-lg border border-line px-3.5 py-3">
                                <dt class="text-xs text-muted">{{ $label }}</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ $value === null ? '–' : \Illuminate\Support\Number::format($value, maxPrecision: 2).'%' }}</dd>
                            </div>
                        @endforeach
                        <div class="rounded-lg border border-line px-3.5 py-3">
                            <dt class="text-xs text-muted">{{ __('Reactietijd (24 uur)') }}</dt>
                            <dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ ($response = $site->averageResponseTime()) === null ? '–' : $response.' ms' }}</dd>
                        </div>
                    </dl>

                    @if ($this->recentChecks->isNotEmpty())
                        <div class="grid gap-1.5">
                            <x-uptime-bar :checks="$this->recentChecks" />
                            <div class="flex justify-between text-2xs text-faint">
                                <span>{{ $this->recentChecks->first()->checked_at->diffForHumans() }}</span>
                                <span>{{ __('Laatste meting :time', ['time' => $this->recentChecks->last()->checked_at->diffForHumans()]) }}</span>
                            </div>
                        </div>
                    @else
                        <p class="text-[0.8125rem] text-muted">{{ __('Nog geen metingen. De eerste volgt binnen een minuut, of meet nu direct.') }}</p>
                    @endif
                @endif
            </div>
        @endunless
    </x-panel>

    @if ($site->hasLiveUrl() && $site->uptime_enabled)
        <x-panel :title="__('Storingen')" :padding="false">
            @if ($this->incidents->isEmpty())
                <p class="px-5 py-4 text-muted">{{ __('Geen storingen gemeten.') }}</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->incidents as $incident)
                        <li wire:key="incident-{{ $incident->id }}" class="flex items-center gap-3 px-5 py-3">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-danger animate-pulse' => $incident->isOngoing(), 'bg-faint' => ! $incident->isOngoing()])></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ $incident->started_at->isoFormat('D MMM, HH:mm') }}
                                    <span class="font-normal text-muted">· {{ $incident->isOngoing() ? __('duurt nog') : trans_choice(':count minuut|:count minuten', max(1, $incident->durationInMinutes())) }}</span>
                                </p>
                                @if ($incident->reason)
                                    <p class="truncate text-[0.8125rem] text-muted">{{ $incident->reason }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>
    @endif
</div>
