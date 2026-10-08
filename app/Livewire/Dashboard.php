<?php

namespace App\Livewire;

use App\Enums\SiteProvider;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Sites')]
class Dashboard extends Component
{
    use InteractsWithSites;

    #[Url(as: 'zoek', except: '')]
    public string $search = '';

    #[Url(as: 'provider', except: '')]
    public string $provider = '';

    #[Url(as: 'filter', except: 'all')]
    public string $scope = 'all';

    /**
     * Snelle start: de lijst komt uit de database, de echte stand wordt daarna op de achtergrond opgehaald.
     */
    public function syncIfStale(SiteRegistry $registry): void
    {
        $oldest = Site::query()->min('synced_at');

        if ($oldest === null || now()->diffInSeconds($oldest, absolute: true) > 30) {
            $this->refreshSites($registry);
        }
    }

    public function refreshSites(SiteRegistry $registry): void
    {
        $this->attempt(fn (): int => $registry->sync());

        unset($this->sites, $this->stats);
        $this->dispatch('sites-changed');
    }

    public function import(CommandRunner $runner): void
    {
        $this->queueCommand($runner, WpOpenCommand::import());
    }

    #[On('sites-changed')]
    #[On('run-started')]
    public function sitesChanged(): void
    {
        unset($this->sites, $this->stats, $this->pendingSiteChanges);
    }

    /**
     * @return SupportCollection<int, Site>
     */
    #[Computed]
    public function sites(): SupportCollection
    {
        return Site::query()
            ->when($this->search !== '', fn ($query) => $query->search($this->search))
            ->when(SiteProvider::tryFrom($this->provider), fn ($query, SiteProvider $provider) => $query->provider($provider))
            ->when($this->scope === 'favorites', fn ($query) => $query->favorite())
            ->ordered()
            ->get()
            ->filter(fn (Site $site): bool => match ($this->scope) {
                'built' => $site->isBuilt(),
                'pending' => $site->pendingChanges() > 0,
                default => true,
            })
            ->values();
    }

    /**
     * @return array{total: int, built: int, running: int, pending: int}
     */
    #[Computed]
    public function stats(): array
    {
        $all = Site::query()->get(['id', 'snapshot']);

        return [
            'total' => $all->count(),
            'built' => $all->filter->isBuilt()->count(),
            'running' => $all->filter->isRunning()->count(),
            'pending' => $all->filter(fn (Site $site): bool => $site->pendingChanges() > 0)->count(),
        ];
    }

    /**
     * Toevoegen, maken en verwijderen die nog lopen: de lijst ververst zichzelf tot ze klaar zijn.
     *
     * @return Collection<int, CommandRun>
     */
    #[Computed]
    public function pendingSiteChanges(): Collection
    {
        return CommandRun::query()
            ->active()
            ->whereIn('action', [WpOpenAction::AddSite, WpOpenAction::NewSite, WpOpenAction::RemoveSite, WpOpenAction::Import])
            ->latestFirst()
            ->get();
    }

    public function pollSiteChanges(): void
    {
        unset($this->sites, $this->stats, $this->pendingSiteChanges, $this->recentRuns);
    }

    /**
     * @return Collection<int, CommandRun>
     */
    #[Computed]
    public function recentRuns(): Collection
    {
        return CommandRun::query()->latestFirst()->limit(6)->get();
    }

    public function render(): View
    {
        return view('livewire.dashboard', ['providers' => SiteProvider::cases()]);
    }
}
