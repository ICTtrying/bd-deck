<?php

namespace App\Livewire;

use App\Models\CommandRun;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Sidebar extends Component
{
    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function favorites(): Collection
    {
        return Site::query()->favorite()->ordered()->get(['id', 'name', 'snapshot', 'uptime_enabled', 'uptime_status']);
    }

    /**
     * @return Collection<int, CommandRun>
     */
    #[Computed]
    public function activeRuns(): Collection
    {
        return CommandRun::query()->active()->latestFirst()->limit(5)->get(['id', 'label', 'site_name', 'status', 'created_at']);
    }

    /**
     * Waar de badge bij Uptime & onderhoud op staat: storingen eerst, daarna updates.
     *
     * @return array{offline: int, warnings: int}
     */
    #[Computed]
    public function attention(): array
    {
        $sites = Site::query()->get(['id', 'name', 'snapshot', 'uptime_enabled', 'uptime_status', 'updates']);

        return [
            'offline' => $sites->filter->isOffline()->count(),
            'warnings' => $sites->filter(fn (Site $site): bool => ! $site->isOffline() && $site->updatesCount() > 0)->count(),
        ];
    }

    #[On('sites-changed')]
    #[On('run-started')]
    public function refreshSidebar(): void
    {
        unset($this->favorites, $this->activeRuns, $this->attention);
    }

    public function poll(): void
    {
        $this->refreshSidebar();
    }

    public function render(): View
    {
        return view('livewire.sidebar');
    }
}
