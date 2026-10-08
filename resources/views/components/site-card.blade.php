@props(['site'])

@php
    /** @var \App\Models\Site $site */
    $updates = $site->updatesCount();
    $quick = 'grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-raised hover:text-ink';
@endphp

<article {{ $attributes->merge(['class' => 'grid gap-4 rounded-xl border border-line bg-surface p-4 transition-colors hover:border-line-strong']) }}>
    <header class="flex items-start justify-between gap-3">
        <div class="grid min-w-0 gap-1.5">
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="truncate text-[0.9375rem] font-semibold tracking-[-0.005em] hover:text-local-ink">{{ $site->name }}</a>
            <div class="flex flex-wrap items-center gap-1.5">
                <x-provider-badge :provider="$site->providerType" />
                @unless ($site->mode->hasShell())
                    <x-badge>{{ __('SFTP') }}</x-badge>
                @endunless
                @if ($updates > 0)
                    <x-badge tone="warning">{{ trans_choice(':count update|:count updates', $updates) }}</x-badge>
                @endif
                @if ($site->healthTone() === 'danger')
                    <x-badge tone="danger">{{ __('Verbinding mislukt') }}</x-badge>
                @endif
            </div>
        </div>
        <button type="button" wire:click="toggleFavorite({{ $site->id }})" @class([
            '-mr-1 -mt-1 grid size-8 place-items-center rounded-lg transition-colors hover:bg-raised',
            'text-warning' => $site->is_favorite,
            'text-faint hover:text-muted' => ! $site->is_favorite,
        ]) aria-pressed="{{ $site->is_favorite ? 'true' : 'false' }}" title="{{ $site->is_favorite ? __('Uit favorieten') : __('Favoriet maken') }}">
            <x-icon name="star" :class="$site->is_favorite ? 'fill-current' : ''" />
            <span class="sr-only">{{ __('Favoriet') }}</span>
        </button>
    </header>

    <x-site-bridge :site="$site" />

    <footer class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-0.5">
            @if ($site->isBuilt())
                <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'local-admin')" title="{{ __('WP-admin lokaal') }}"><x-icon name="wordpress" /><span class="sr-only">{{ __('WP-admin lokaal') }}</span></button>
                <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'editor')" title="{{ __('Openen in editor') }}"><x-icon name="code" /><span class="sr-only">{{ __('Openen in editor') }}</span></button>
                <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'terminal')" title="{{ __('Terminal in projectmap') }}"><x-icon name="terminal" /><span class="sr-only">{{ __('Terminal') }}</span></button>
                <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'folder')" title="{{ __('Map openen') }}"><x-icon name="folder" /><span class="sr-only">{{ __('Map openen') }}</span></button>
                <span class="mx-1 h-4 w-px bg-line"></span>
            @else
                <x-button size="sm" variant="primary" icon="hammer" wire:click="runQuick({{ $site->id }}, 'build')">{{ __('Lokaal bouwen') }}</x-button>
                <span class="mx-1 h-4 w-px bg-line"></span>
            @endif
            <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'live-site')" title="{{ __('Live website openen') }}"><x-icon name="globe" /><span class="sr-only">{{ __('Live website') }}</span></button>
            <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'live-admin')" title="{{ __('WP-admin live') }}"><x-icon name="external" /><span class="sr-only">{{ __('WP-admin live') }}</span></button>
            <button type="button" class="{{ $quick }}" wire:click="launch({{ $site->id }}, 'ssh')" title="{{ $site->mode->hasShell() ? __('SSH-sessie') : __('SFTP-sessie') }}"><x-icon name="server" /><span class="sr-only">{{ __('SSH') }}</span></button>
        </div>
        <a href="{{ route('sites.show', $site) }}" wire:navigate class="text-[0.8125rem] text-faint hover:text-muted" title="{{ $site->last_activity_at?->isoFormat('LLL') }}">
            {{ $site->last_activity_at?->diffForHumans(short: true) ?? __('Nog niets gedaan') }}
        </a>
    </footer>
</article>
