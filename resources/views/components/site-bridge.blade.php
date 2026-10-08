@props(['site', 'flow' => null, 'size' => 'sm'])

@php
    /** @var \App\Models\Site $site */
    $pending = $site->pendingChanges();
    $localState = match (true) {
        ! $site->isBuilt() => ['label' => __('Nog niet gebouwd'), 'tone' => 'muted'],
        $site->isRunning() => ['label' => __('Draait'), 'tone' => 'ok'],
        default => ['label' => __('Gestopt'), 'tone' => 'muted'],
    };
    $liveHealth = collect($site->health ?? [])->firstWhere('key', 'http');
    $liveState = match ($liveHealth['status'] ?? null) {
        'ok' => ['label' => __('Online'), 'tone' => 'ok'],
        'fail' => ['label' => __('Niet bereikbaar'), 'tone' => 'bad'],
        default => ['label' => __('Niet getest'), 'tone' => 'muted'],
    };
    $middle = match (true) {
        $flow === 'push' => __('Naar live…'),
        $flow === 'pull' => __('Ophalen…'),
        ! $site->isBuilt() => __('—'),
        $pending > 0 => trans_choice(':count wijziging klaar|:count wijzigingen klaar', $pending),
        default => __('Gelijk'),
    };
    $dot = fn (string $tone): string => match ($tone) {
        'ok' => 'bg-success',
        'bad' => 'bg-danger',
        default => 'bg-faint',
    };
    $large = $size === 'lg';
@endphp

<div {{ $attributes->merge(['class' => 'grid grid-cols-[1fr_auto_1fr] items-stretch']) }}>
    {{-- lokaal: de B, inkt --}}
    <div @class([
        'grid content-center rounded-l-lg border border-r-0 border-local/15 bg-local-soft text-local-ink',
        'gap-0.5 px-3 py-2' => ! $large,
        'gap-3 px-5 py-4' => $large,
    ])>
        <div class="flex items-center gap-1.5">
            <x-icon name="laptop" :size="$large ? 16 : 13" />
            <span @class(['font-semibold', 'text-xs' => ! $large, 'text-sm' => $large])>{{ __('Lokaal') }}</span>
        </div>
        <div class="flex items-center gap-1.5 text-xs text-ink/80">
            <span class="size-1.5 shrink-0 rounded-full {{ $dot($localState['tone']) }}"></span>
            <span class="truncate">{{ $localState['label'] }}</span>
        </div>
        @if ($large && isset($local))
            <div class="flex flex-wrap gap-1.5">{{ $local }}</div>
        @endif
    </div>

    {{-- de verbinding: wat er klaarstaat --}}
    <div @class(['relative flex flex-col items-center justify-center border-y border-line bg-surface', 'min-w-24 px-2' => ! $large, 'min-w-56 gap-2 px-4' => $large])>
        <div class="bridge-track absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2" @if ($flow) data-flow="{{ $flow }}" @endif></div>
        <span @class([
            'relative rounded-full border bg-surface px-2 py-0.5 text-2xs font-medium whitespace-nowrap',
            'border-live/40 text-live-ink' => $pending > 0 || $flow === 'push',
            'border-local/40 text-local-ink' => $flow === 'pull',
            'border-line text-muted' => $pending === 0 && ! $flow,
        ])>{{ $middle }}</span>
        @if ($large && isset($actions))
            <div class="relative flex flex-wrap justify-center gap-2">{{ $actions }}</div>
        @endif
    </div>

    {{-- live: de D, lagune --}}
    <div @class([
        'grid content-center rounded-r-lg border border-l-0 border-live/25 bg-live-soft text-live-ink',
        'gap-0.5 px-3 py-2 text-right' => ! $large,
        'gap-3 px-5 py-4' => $large,
    ])>
        <div @class(['flex items-center gap-1.5', 'justify-end' => ! $large])>
            <span @class(['font-semibold', 'text-xs' => ! $large, 'text-sm' => $large])>{{ __('Live') }}</span>
            <x-icon name="globe" :size="$large ? 16 : 13" />
        </div>
        <div @class(['flex items-center gap-1.5 text-xs text-ink/80', 'justify-end' => ! $large])>
            <span class="size-1.5 shrink-0 rounded-full {{ $dot($liveState['tone']) }}"></span>
            <span class="truncate">{{ $large ? $site->data('live_host') : $liveState['label'] }}</span>
        </div>
        @if ($large && isset($live))
            <div class="flex flex-wrap gap-1.5">{{ $live }}</div>
        @endif
    </div>
</div>
