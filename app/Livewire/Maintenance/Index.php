<?php

namespace App\Livewire\Maintenance;

use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\UpdateChecker;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Uptime & onderhoud')]
class Index extends Component
{
    use InteractsWithSites;

    /**
     * Een back-up ouder dan dit aantal dagen telt als verouderd.
     */
    public const BACKUP_WARNING_DAYS = 30;

    public function checkAll(UpdateChecker $checker): void
    {
        $queued = $checker->queue($checker->checkable());

        unset($this->checking);

        $this->dispatch('toast', title: $queued > 0 ? __('Updates worden gecontroleerd') : __('Er is niets om te controleren'), message: $queued > 0 ? trans_choice(':count site|:count sites', $queued) : null, tone: 'info');
    }

    #[On('sites-changed')]
    #[On('run-started')]
    public function refresh(): void
    {
        unset($this->sites, $this->summary, $this->checking);
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function sites(): Collection
    {
        return Site::query()->withUptimeCounts()->ordered()->get()->reject(fn (Site $site): bool => $site->isLocalOnly())->values();
    }

    /**
     * Sites waarvan de updatecontrole nog loopt, voor het meepollen.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function checking(): Collection
    {
        return CommandRun::query()->active()->where('action', WpOpenAction::Updates)->pluck('site_id');
    }

    /**
     * @return array{offline: int, updates: int, backups: int}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'offline' => $this->sites->filter(fn (Site $site): bool => $site->isOffline())->count(),
            'updates' => $this->sites->sum(fn (Site $site): int => $site->updatesCount()),
            'backups' => $this->sites->filter(fn (Site $site): bool => $this->backupIsStale($site))->count(),
        ];
    }

    public function backupIsStale(Site $site): bool
    {
        $backup = $site->lastBackupAt();

        return $backup === null || $backup->lt(now()->subDays(self::BACKUP_WARNING_DAYS));
    }

    public function poll(): void
    {
        unset($this->sites, $this->summary, $this->checking);
    }

    public function render(): View
    {
        return view('livewire.maintenance.index');
    }
}
