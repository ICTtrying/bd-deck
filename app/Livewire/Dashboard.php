<?php

namespace App\Livewire;

use App\Enums\SiteProvider;
use App\Enums\UptimeStatus;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpenCommand;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
use InvalidArgumentException;
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
     * Aangevinkte sites (id's) voor de acties in de balk onderaan.
     *
     * @var list<int>
     */
    public array $selected = [];

    public bool $backupLocal = true;

    public bool $backupLiveDatabase = true;

    public bool $backupLiveFiles = false;

    /**
     * Sites die op bevestiging wachten voor "live cache legen".
     *
     * @var list<int>
     */
    public array $liveCacheSiteIds = [];

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

        unset($this->sites, $this->stats, $this->offlineSites);
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
        unset($this->sites, $this->stats, $this->pendingSiteChanges, $this->offlineSites);
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
                'pending' => $site->pendingChanges() > 0,
                'updates' => $site->updatesCount() > 0,
                default => true,
            })
            ->values();
    }

    /**
     * @return array{total: int, pending: int, updates: int}
     */
    #[Computed]
    public function stats(): array
    {
        $all = Site::query()->get(['id', 'snapshot', 'updates']);

        return [
            'total' => $all->count(),
            'pending' => $all->filter(fn (Site $site): bool => $site->pendingChanges() > 0)->count(),
            'updates' => $all->filter(fn (Site $site): bool => $site->updatesCount() > 0)->count(),
        ];
    }

    /**
     * Het aantal verschillende providers; bij één provider is een providerfilter ruis.
     */
    #[Computed]
    public function providerCount(): int
    {
        return Site::query()->get(['id', 'snapshot'])->pluck('providerType')->unique()->count();
    }

    /**
     * Sites waarvan de uptime-bewaking een storing heeft bevestigd.
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function offlineSites(): Collection
    {
        return Site::query()
            ->where('uptime_enabled', true)
            ->where('uptime_status', UptimeStatus::Down->value)
            ->ordered()
            ->get(['id', 'name', 'is_favorite', 'uptime_status_since']);
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

    public function updatedSelected(): void
    {
        $this->selected = array_values(array_unique(array_map('intval', $this->selected)));
        unset($this->selectedSites);
    }

    /**
     * Alleen bestaande sites: een verwijderde of verzonnen id telt niet mee.
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function selectedSites(): Collection
    {
        return Site::query()->whereKey($this->selected)->ordered()->get();
    }

    /**
     * Werkt op de zichtbare (gefilterde) lijst: alles aan, of uit als alles al aan stond.
     */
    public function toggleSelectAll(): void
    {
        $visible = $this->sites->pluck('id')->all();

        $this->selected = array_diff($visible, $this->selected) === []
            ? array_values(array_diff($this->selected, $visible))
            : array_values(array_unique([...$this->selected, ...$visible]));

        unset($this->selectedSites);
    }

    public function clearSelection(): void
    {
        $this->reset('selected', 'liveCacheSiteIds');
        unset($this->selectedSites);
    }

    public function bulkUpdates(CommandRunner $runner): void
    {
        // net als de controle op de achtergrond: alleen een melding als er iets nieuws is
        $this->queueForSites($runner, WpOpenAction::Updates, $this->selectedSites, function (Site $site): WpOpenCommand|string {
            if (($site->isLocalOnly() || $site->isLaravel()) && ! $site->isBuilt()) {
                return __('nog niet gebouwd');
            }

            return WpOpenCommand::updates($site);
        }, notify: false);
    }

    public function bulkCache(string $where, CommandRunner $runner): void
    {
        if ($where === 'live') {
            $sites = $this->selectedSites->reject->isLocalOnly();

            // live voor meerdere sites tegelijk: eerst laten zien welke het zijn
            if ($sites->count() > 1) {
                $this->liveCacheSiteIds = array_values($sites->modelKeys());
                unset($this->liveCacheSites);
                $this->dispatch('open-modal', name: 'bulk-live-cache');

                return;
            }
        }

        $this->queueCache($runner, $this->selectedSites, live: $where === 'live');
    }

    public function confirmLiveCache(CommandRunner $runner): void
    {
        $sites = Site::query()->whereKey(array_intersect($this->liveCacheSiteIds, $this->selected))->ordered()->get();

        $this->dispatch('close-modal', name: 'bulk-live-cache');
        $this->queueCache($runner, $sites, live: true);
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function liveCacheSites(): Collection
    {
        return Site::query()->whereKey($this->liveCacheSiteIds)->ordered()->get(['id', 'name', 'snapshot']);
    }

    public function bulkBackup(CommandRunner $runner): void
    {
        if (! $this->backupLocal && ! $this->backupLiveDatabase && ! $this->backupLiveFiles) {
            $this->dispatch('toast', title: __('Kies minstens één onderdeel voor de back-up.'), tone: 'warning');

            return;
        }

        $this->queueForSites($runner, WpOpenAction::Backup, $this->selectedSites, function (Site $site): WpOpenCommand|string {
            $local = $this->backupLocal && $site->isBuilt();
            $live = ! $site->isLocalOnly();

            try {
                return WpOpenCommand::backup($site, local: $local, liveDatabase: $this->backupLiveDatabase && $live, liveFiles: $this->backupLiveFiles && $live);
            } catch (InvalidArgumentException) {
                // geen enkel gekozen onderdeel past bij deze site
                return $this->backupLocal && ! $site->isBuilt() ? __('nog niet gebouwd') : __('geen live-server');
            }
        });
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function queueCache(CommandRunner $runner, Collection $sites, bool $live): void
    {
        $this->queueForSites($runner, $live ? WpOpenAction::CacheLive : WpOpenAction::CacheLocal, $sites, function (Site $site) use ($live): WpOpenCommand|string {
            return match (true) {
                $live && $site->isLocalOnly() => __('geen live-server'),
                ! $live && ! $site->isBuilt() => __('nog niet gebouwd'),
                default => WpOpenCommand::cache($site, live: $live),
            };
        });
    }

    /**
     * Eén run per site waar de actie past, daarna één samenvatting in plaats van een melding per site.
     *
     * @param  Collection<int, Site>  $sites
     * @param  Closure(Site): (WpOpenCommand|string)  $plan  het commando, of de reden om de site over te slaan
     */
    private function queueForSites(CommandRunner $runner, WpOpenAction $action, Collection $sites, Closure $plan, bool $notify = true): void
    {
        $started = 0;
        $skipped = [];
        $lastRun = null;

        foreach ($sites as $site) {
            $command = $plan($site);

            if (is_string($command)) {
                $skipped[$command] = ($skipped[$command] ?? 0) + 1;

                continue;
            }

            $lastRun = $runner->queue($command, notify: $notify);
            $started++;
        }

        $this->clearSelection();

        if ($lastRun !== null) {
            $this->dispatch('run-started', runId: $lastRun->id);
        }

        $this->dispatch('toast', title: $action->label(), message: $this->bulkSummary($started, $skipped), tone: $started > 0 ? 'info' : 'warning');
    }

    /**
     * "Gestart voor 4 sites · 1 overgeslagen (nog niet gebouwd)"
     *
     * @param  array<string, int>  $skipped  reden → aantal
     */
    private function bulkSummary(int $started, array $skipped): string
    {
        $parts = [trans_choice('{0} Voor geen enkele site gestart|{1} Gestart voor één site|[2,*] Gestart voor :count sites', $started)];

        if ($skipped !== []) {
            $reasons = count($skipped) === 1
                ? array_key_first($skipped)
                : collect($skipped)->map(fn (int $count, string $reason): string => $count.' '.$reason)->implode(', ');

            $parts[] = __(':count overgeslagen (:reasons)', ['count' => array_sum($skipped), 'reasons' => $reasons]);
        }

        return implode(' · ', $parts);
    }

    public function render(): View
    {
        return view('livewire.dashboard', ['providers' => SiteProvider::cases()]);
    }
}
