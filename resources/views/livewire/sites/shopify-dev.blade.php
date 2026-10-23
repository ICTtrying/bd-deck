@php
    /** @var \App\Models\Site $site */
    $active = $this->activeRun;
    $running = $site->isRunning();
    $preview = $site->devPreviewUrl();
    $share = $site->devShareUrl();
    $editor = $site->devEditorUrl();
    $hasPassword = $site->storePasswordCredential() !== null;
@endphp

{{-- zolang de server draait of net start/stopt: elke paar seconden opnieuw, zodat het log meeloopt --}}
<div @if ($running || $active) wire:poll.3s="refreshSite" @endif>
    <x-panel :title="__('Dev server')" :description="$running ? __('Draait op poort :port; wijzigingen gaan meteen naar je dev-thema in :store.', ['port' => $site->devPort(), 'store' => $site->shopifyStore()]) : __('Start een tijdelijk dev-thema in :store en bekijk je wijzigingen direct.', ['store' => $site->shopifyStore()])">
        <x-slot:actions>
            @if ($active)
                <x-button size="sm" icon="loader" disabled>{{ $active->label }}…</x-button>
            @elseif ($running)
                <x-button size="sm" icon="globe" wire:click="launch({{ $site->id }}, 'local-site')">{{ __('Preview') }}</x-button>
                <x-button size="sm" variant="danger-ghost" icon="square" wire:click="toggle" title="{{ __('Sneltoets: d') }}">{{ __('Stoppen') }}</x-button>
            @else
                <x-button size="sm" variant="primary" icon="play" wire:click="startAndOpen" title="{{ __('Sneltoets: d (start), p (start en preview)') }}">{{ __('Starten en preview openen') }}</x-button>
                <x-button size="sm" icon="play" wire:click="toggle">{{ __('Alleen starten') }}</x-button>
            @endif
        </x-slot:actions>

        <div class="grid gap-4">
            <div class="flex flex-wrap items-center gap-2">
                @if ($running)
                    <x-badge tone="success" dot>{{ __('Draait') }}</x-badge>
                    @if ($site->data('shopify.dev.started_at'))
                        <span class="text-[0.8125rem] text-muted">{{ __('sinds :time', ['time' => \Illuminate\Support\Carbon::parse($site->data('shopify.dev.started_at'))->diffForHumans()]) }}</span>
                    @endif
                @else
                    <x-badge>{{ __('Gestopt') }}</x-badge>
                @endif
                @if ($site->data('shopify.theme_editor_sync'))
                    <x-badge tone="info" title="{{ __('Wijzigingen in de theme editor komen terug in je lokale bestanden') }}">{{ __('Editor-sync aan') }}</x-badge>
                @endif
                @if ($site->data('shopify.host') === '0.0.0.0')
                    <x-badge tone="warning" title="{{ __('De preview is bereikbaar voor je hele netwerk') }}">{{ __('Hele netwerk') }}</x-badge>
                @endif
                @if ($hasPassword)
                    <x-badge title="{{ __('Het storefront-wachtwoord staat in de kluis en gaat vanzelf mee') }}"><x-icon name="lock" :size="11" />{{ __('Wachtwoordpagina') }}</x-badge>
                @endif
            </div>

            @if ($running)
                <dl class="grid grid-cols-[auto_1fr] items-center gap-x-5 gap-y-2.5 text-sm">
                    <dt class="text-muted">{{ __('Preview') }}</dt>
                    <dd class="flex min-w-0 flex-wrap items-center gap-2">
                        <a href="{{ $preview }}" class="min-w-0 truncate font-mono text-[0.8125rem] hover:text-local-ink" wire:click.prevent="launch({{ $site->id }}, 'local-site')">{{ $preview }}</a>
                        <x-copy-button :text="$preview" class="!h-7" />
                    </dd>
                    @if ($share)
                        <dt class="text-muted">{{ __('Deelbare link') }}</dt>
                        <dd class="flex min-w-0 flex-wrap items-center gap-2">
                            <a href="{{ $share }}" class="min-w-0 truncate font-mono text-[0.8125rem] hover:text-local-ink" wire:click.prevent="launch({{ $site->id }}, 'share')">{{ $share }}</a>
                            <x-copy-button :text="$share" class="!h-7" />
                        </dd>
                    @endif
                    @if ($editor)
                        <dt class="text-muted">{{ __('Theme editor') }}</dt>
                        <dd><x-button size="sm" icon="pencil" wire:click="launch({{ $site->id }}, 'local-admin')" title="{{ __('Sneltoets: e') }}">{{ __('Openen') }}</x-button></dd>
                    @endif
                </dl>

                @if ($share)
                    <details class="rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]">
                        <summary class="cursor-pointer font-medium">{{ __('Bericht voor de klant') }}</summary>
                        <p class="mt-2 whitespace-pre-line">{{ $this->clientMessage() }}</p>
                        <x-copy-button :text="$this->clientMessage()" :label="__('Bericht kopiëren')" class="mt-2" />
                        <p class="mt-2 text-muted">{{ __('De link toont het dev-thema van jou, zolang de dev server draait. Heeft de winkel een wachtwoordpagina, dan moet de klant dat wachtwoord ook weten.') }}</p>
                    </details>
                @endif
            @endif

            <div class="grid gap-1.5">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-[0.8125rem] font-semibold">{{ __('Dev-log') }}</h3>
                    <span class="text-xs text-faint">{{ __('Het wachtwoord wordt nooit getoond') }}</span>
                </div>
                @if ($this->log === [])
                    <p class="rounded-lg border border-dashed border-line px-3 py-3 text-[0.8125rem] text-muted">{{ __('Nog geen log: dat verschijnt zodra je de dev server start.') }}</p>
                @else
                    <div role="log" class="max-h-56 overflow-auto rounded-lg bg-console px-3 py-2 font-mono text-xs leading-5 text-console-ink">@foreach ($this->log as $line)<x-log-line :line="$line" />@endforeach</div>
                @endif
            </div>
        </div>
    </x-panel>
</div>
