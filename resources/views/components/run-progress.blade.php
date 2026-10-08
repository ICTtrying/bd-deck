@props(['run'])

@php
    /** @var \App\Models\CommandRun $run */
    $steps = $run->steps();
    $tail = collect(explode("\n", trim((string) $run->output)))->filter(fn ($line) => trim($line) !== '')->take(-4);
@endphp

<section class="grid gap-3 rounded-xl border border-live/30 bg-live-soft/60 p-4" aria-live="polite">
    <div class="flex flex-wrap items-center gap-3">
        <x-icon name="loader" class="animate-spin text-live-ink" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">{{ $run->label }}</p>
            <p class="text-[0.8125rem] text-muted">
                {{ $run->status->label() }}@if ($run->durationInSeconds() !== null), {{ \Carbon\CarbonInterval::seconds($run->durationInSeconds())->cascade()->forHumans(short: true) }}@endif
            </p>
        </div>
        <x-button size="sm" variant="ghost" :href="route('activity.show', $run)" wire:navigate>{{ __('Volledig log') }}</x-button>
        @if ($run->status !== \App\Enums\RunStatus::Cancelling)
            <x-button size="sm" variant="danger-ghost" icon="square" wire:click="cancelRun({{ $run->id }})">{{ __('Stoppen') }}</x-button>
        @endif
    </div>

    @if ($steps->isNotEmpty())
        <ol class="flex flex-wrap gap-x-4 gap-y-1 text-[0.8125rem]">
            @foreach ($steps as $index => $step)
                <li @class(['flex items-center gap-1.5', 'text-muted' => ! $loop->last, 'font-medium text-live-ink' => $loop->last])>
                    <x-icon :name="$loop->last ? 'arrow-right' : 'check'" :size="12" />{{ $step }}
                </li>
            @endforeach
        </ol>
    @endif

    @if ($tail->isNotEmpty())
        <div role="log" class="overflow-x-auto rounded-lg bg-console px-3 py-2 font-mono text-xs leading-5 text-console-ink">@foreach ($tail as $line)<x-log-line :line="$line" />@endforeach</div>
    @endif
</section>
