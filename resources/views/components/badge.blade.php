@props(['tone' => 'neutral', 'dot' => false])

@php
    $tones = [
        'neutral' => 'bg-raised text-muted border-line',
        'local' => 'bg-local-soft text-local-ink border-transparent',
        'live' => 'bg-live-soft text-live-ink border-transparent',
        'success' => 'bg-success-soft text-success border-transparent',
        'warning' => 'bg-warning-soft text-warning border-transparent',
        'danger' => 'bg-danger-soft text-danger border-transparent',
        'info' => 'bg-live-soft text-live-ink border-transparent',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-md border px-1.5 py-px text-xs font-medium '.$tones[$tone]]) }}>
    @if ($dot)
        <span @class(['size-1.5 rounded-full bg-current', 'animate-pulse' => $tone === 'info'])></span>
    @endif
    {{ $slot }}
</span>
