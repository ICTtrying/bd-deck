<?php

namespace App\Livewire\Sites;

use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class SafeUpdatePanel extends Component
{
    use InteractsWithSites;

    public Site $site;

    /**
     * Aangevinkte updates als 'core', 'plugin:slug' of 'theme:slug'.
     *
     * @var list<string>
     */
    public array $selected = [];

    public bool $live = true;

    /**
     * Met $without: opnieuw proberen na een mislukte run, met dezelfde keuze maar zonder de update die de fout gaf.
     */
    #[On('open-safe-update')]
    public function open(?string $without = null): void
    {
        $this->site->refresh();
        $this->resetValidation();
        $this->live = true;
        $this->selected = $this->selectableKeys();

        if ($without !== null) {
            $previous = $this->previousSelection();

            $this->selected = array_values(array_diff(
                $previous === [] ? $this->selected : array_intersect($this->selected, $previous),
                [$without],
            ));
        }

        $this->dispatch('open-modal', name: 'safe-update');
    }

    public function start(CommandRunner $runner): void
    {
        $selectable = $this->selectableKeys();
        $selected = array_values(array_intersect($selectable, $this->selected));

        if ($selected === []) {
            $this->addError('selected', __('Kies minstens één update.'));

            return;
        }

        $this->attempt(function () use ($runner, $selectable, $selected): void {
            $this->queueCommand($runner, WpOpenCommand::safeUpdate(
                $this->site,
                // alles gekozen: dan neemt het script ook updates mee die na de laatste controle zijn verschenen
                only: count($selected) === count($selectable) ? [] : $selected,
                live: $this->live,
            ));

            $this->dispatch('close-modal', name: 'safe-update');
        });
    }

    /**
     * Beschikbare updates uit de laatste controle op live.
     *
     * @return Collection<int, array{key: string, name: string, type: string, from: ?string, to: string, selectable: bool}>
     */
    public function items(): Collection
    {
        $updates = $this->site->updates ?? [];
        $hasShell = $this->site->mode->hasShell();

        return collect($updates['core'] ?? [])
            ->take(1)
            ->map(fn (array $core): array => [
                'key' => 'core', 'name' => 'WordPress', 'type' => __('kern'),
                'from' => null, 'to' => (string) ($core['version'] ?? ''), 'selectable' => $hasShell,
            ])
            ->concat(collect(['plugins' => 'plugin', 'themes' => 'theme'])->flatMap(
                fn (string $kind, string $group): array => collect($updates[$group] ?? [])
                    ->map(fn (array $item): array => [
                        'key' => $kind.':'.$item['name'],
                        'name' => ($item['title'] ?? '') ?: $item['name'],
                        'type' => $kind === 'plugin' ? __('plugin') : __('thema'),
                        'from' => $item['version'] ?? null,
                        'to' => (string) ($item['update_version'] ?? ''),
                        'selectable' => (bool) preg_match('/^[A-Za-z0-9._-]+$/', (string) $item['name']),
                    ])
                    ->all()
            ))
            ->values();
    }

    /**
     * Een controle van meer dan een uur oud kan achterlopen; het script kijkt dan zelf opnieuw.
     */
    public function isStale(): bool
    {
        return $this->site->updates_checked_at === null || $this->site->updates_checked_at->lt(now()->subHour());
    }

    /**
     * De --only-keuze van de laatste veilige update; leeg betekent dat toen alles gekozen was.
     *
     * @return list<string>
     */
    private function previousSelection(): array
    {
        $arguments = $this->site->runs()->where('action', WpOpenAction::SafeUpdate)->latestFirst()->first()->arguments ?? [];

        return collect($arguments)
            ->filter(fn (mixed $argument, int $index): bool => ($arguments[$index - 1] ?? null) === '--only')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function selectableKeys(): array
    {
        return $this->items()->where('selectable', true)->pluck('key')->values()->all();
    }

    public function render(): View
    {
        return view('livewire.sites.safe-update-panel');
    }
}
