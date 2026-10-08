@php
    /** @var \App\Models\CommandRun $run */
    $lines = explode("\n", rtrim((string) $run->output));
@endphp

<div class="grid gap-6" @if ($run->status->isActive()) wire:poll.750ms="refreshRun" @endif>
    <x-page-header :title="$run->label" :back="$run->site ? route('sites.show', $run->site) : route('activity.index')">
        <x-slot:description>
            <span class="flex flex-wrap items-center gap-2">
                <x-badge :tone="$run->status->tone()" :dot="$run->status->isActive()">{{ $run->status->label() }}</x-badge>
                @if ($run->site_name)<span>{{ $run->site_name }}</span>@endif
                <span class="text-faint">{{ $run->created_at->isoFormat('D MMMM YYYY, HH:mm:ss') }}</span>
                @if ($run->durationInSeconds() !== null)
                    <span class="text-faint">{{ __('duur :time', ['time' => \Carbon\CarbonInterval::seconds($run->durationInSeconds())->cascade()->forHumans(short: true)]) }}</span>
                @endif
            </span>
        </x-slot:description>
        <x-slot:actions>
            <x-copy-button :text="(string) $run->output" :label="__('Uitvoer kopiëren')" />
            @if (in_array($run->status, [\App\Enums\RunStatus::Queued, \App\Enums\RunStatus::Running], true))
                <x-button variant="danger-ghost" icon="square" wire:click="cancel">{{ __('Stoppen') }}</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($run->steps()->isNotEmpty())
        <ol class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($run->steps() as $step)
                @php $current = $loop->last && $run->status->isActive(); @endphp
                <li @class(['flex items-center gap-2 rounded-lg border px-3 py-2 text-[0.8125rem]', 'border-live/40 bg-live-soft font-medium text-live-ink' => $current, 'border-line bg-surface text-muted' => ! $current])>
                    <x-icon :name="$current ? 'loader' : 'check'" :size="14" :class="$current ? 'animate-spin' : 'text-success'" />
                    {{ $step }}
                </li>
            @endforeach
        </ol>
    @endif

    <section class="overflow-hidden rounded-xl border border-line">
        <div class="flex items-center gap-2 border-b border-white/5 bg-console px-4 py-2.5">
            <x-icon name="terminal" :size="14" class="text-[#5f758d]" />
            <code class="min-w-0 flex-1 truncate font-mono text-xs text-[#8ea2b8]">{{ $run->commandLine() }}</code>
            @if ($run->exit_code !== null)
                <span class="font-mono text-xs text-[#5f758d]">{{ __('code :code', ['code' => $run->exit_code]) }}</span>
            @endif
        </div>
        <div role="log"
            class="max-h-[65vh] min-h-48 overflow-auto bg-console px-4 py-3 font-mono text-xs leading-5 text-console-ink"
            x-data="{ follow: true }"
            x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (follow) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
            x-on:scroll="follow = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 24"
        >@if (trim((string) $run->output) === '')<span class="text-[#5f758d]">{{ $run->status === \App\Enums\RunStatus::Queued ? __('Wacht tot een vorige actie op deze site klaar is…') : __('Nog geen uitvoer…') }}</span>@else @foreach ($lines as $line)<x-log-line :line="$line" />@endforeach @endif</div>
    </section>
</div>
