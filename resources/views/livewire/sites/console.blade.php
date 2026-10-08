<div class="grid gap-5" @if ($this->history->contains(fn ($run) => $run->status->isActive())) wire:poll.1s @endif>
    @php $laravel = $site->isLaravel(); @endphp
    <x-panel :title="$laravel ? 'Artisan' : __('WP-CLI')" :description="$laravel ? __('Voert een artisan-commando uit op de lokale site of op live. De uitvoer komt hieronder en in het logboek.') : __('Voert een WP-CLI-commando uit op de lokale site of op live. De uitvoer komt hieronder en in het logboek.')">
        <form wire:submit="execute" class="grid gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex rounded-lg border border-line bg-raised p-0.5" role="group" aria-label="{{ __('Omgeving') }}">
                    <button type="button" wire:click="$set('live', false)" @disabled(! $site->isBuilt()) @class(['h-7 rounded-md px-2.5 text-[0.8125rem] disabled:opacity-40', 'bg-local-soft font-medium text-local-ink' => ! $live, 'text-muted' => $live])>{{ __('Lokaal') }}</button>
                    <button type="button" wire:click="$set('live', true)" @disabled(! $site->mode->hasShell()) @class(['h-7 rounded-md px-2.5 text-[0.8125rem] disabled:opacity-40', 'bg-live-soft font-medium text-live-ink' => $live, 'text-muted' => ! $live])>{{ __('Live') }}</button>
                </div>
                <div class="relative min-w-64 flex-1">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-[0.8125rem] text-faint">{{ $laravel ? 'php artisan' : 'wp' }}</span>
                    <x-input wire:model="command" mono @class(['pl-24' => $laravel, 'pl-9' => ! $laravel]) :placeholder="$laravel ? 'migrate:status' : __('plugin list --status=active')" :aria-label="$laravel ? __('Artisan-commando') : __('WP-CLI-commando')" autocomplete="off" spellcheck="false" />
                </div>
                <x-button type="submit" :variant="$live ? 'live' : 'primary'" icon="play">{{ __('Uitvoeren') }}</x-button>
            </div>
            @error('command')<p class="text-[0.8125rem] text-danger">{{ $message }}</p>@enderror
            @if ($live)
                <p class="flex items-center gap-1.5 text-[0.8125rem] text-live-ink"><x-icon name="alert" :size="14" />{{ $laravel ? __('Dit draait op de live server. Vragen worden met nee beantwoord; geef --force mee voor bijvoorbeeld migrate.') : __('Dit draait op de live server. Commando\'s die iets wijzigen (update, delete, search-replace) gelden direct voor bezoekers.') }}</p>
            @endif
            @unless ($site->mode->hasShell())
                <p class="text-[0.8125rem] text-faint">{{ $laravel ? __('Deze site heeft alleen SFTP: artisan kan alleen lokaal.') : __('Deze site heeft alleen SFTP: WP-CLI kan alleen lokaal.') }}</p>
            @endunless
            <div class="flex flex-wrap gap-1.5">
                @foreach ($this->suggestions() as $suggestion => $label)
                    <button type="button" wire:click="use('{{ $suggestion }}')" class="rounded-md border border-line px-2 py-0.5 text-[0.8125rem] text-muted transition-colors hover:border-line-strong hover:text-ink" title="{{ $laravel ? 'php artisan' : 'wp' }} {{ $suggestion }}">{{ $label }}</button>
                @endforeach
            </div>
        </form>
    </x-panel>

    @foreach ($this->history as $run)
        <section class="overflow-hidden rounded-xl border border-line" wire:key="cli-{{ $run->id }}">
            <div class="flex items-center gap-3 border-b border-line bg-surface px-4 py-2">
                <span class="min-w-0 flex-1 truncate font-mono text-[0.8125rem]">{{ $run->commandLine() }}</span>
                <x-badge :tone="$run->status->tone()" :dot="$run->status->isActive()">{{ $run->status->label() }}</x-badge>
                <a href="{{ route('activity.show', $run) }}" wire:navigate class="text-[0.8125rem] text-muted hover:text-ink">{{ $run->created_at->diffForHumans(short: true) }}</a>
            </div>
            <div role="log" class="max-h-80 overflow-auto bg-console px-4 py-3 font-mono text-xs leading-5 text-console-ink">@forelse (explode("\n", rtrim((string) $run->output)) as $line)<x-log-line :line="$line" />@empty<span class="text-faint">{{ __('Wacht op uitvoer…') }}</span>@endforelse</div>
        </section>
    @endforeach
</div>
