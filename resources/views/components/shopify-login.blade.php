@props(['run'])

@php
    /** @var \App\Models\CommandRun $run */
    $login = $run->shopifyLogin();
@endphp

{{-- de CLI wacht op een login: de link opent één keer vanzelf (zie Show::openLogin), hier blijft hij zichtbaar --}}
@if ($login)
    <section class="grid gap-3 rounded-xl border border-warning/40 bg-warning-soft px-4 py-3.5" role="alert" x-data x-init="$wire.openLogin({{ $run->id }}, false)">
        <div class="flex flex-wrap items-center gap-3">
            <x-icon name="lock" class="text-warning" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ __('Inloggen bij Shopify') }}</p>
                <p class="text-[0.8125rem] text-muted">{{ __('Open de link, log in en bevestig de code. BD Deck gaat daarna vanzelf verder.') }}</p>
            </div>
            <x-button size="sm" variant="primary" icon="external" wire:click="openLogin({{ $run->id }}, true)">{{ __('Inloglink openen') }}</x-button>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            @if ($login['code'])
                <span class="text-muted">{{ __('Code') }}</span>
                <span class="rounded-md border border-line bg-surface px-2.5 py-1 font-mono text-base font-semibold tracking-widest">{{ $login['code'] }}</span>
                <x-copy-button :text="$login['code']" class="!h-7" />
            @endif
            <span class="min-w-0 truncate font-mono text-xs text-faint">{{ $login['url'] }}</span>
            <x-copy-button :text="$login['url']" :label="__('Link kopiëren')" class="!h-7" />
        </div>
    </section>
@endif
