<?php

namespace App\Livewire;

use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\ScriptInstaller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Sidebar extends Component
{
    protected ScriptInstaller $installer;

    public function boot(ScriptInstaller $installer): void
    {
        $this->installer = $installer;
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function favorites(): Collection
    {
        return Site::query()->favorite()->ordered()->get(['id', 'name', 'snapshot']);
    }

    /**
     * @return Collection<int, CommandRun>
     */
    #[Computed]
    public function activeRuns(): Collection
    {
        return CommandRun::query()->active()->latestFirst()->limit(5)->get(['id', 'label', 'site_name', 'status', 'created_at']);
    }

    #[Computed]
    public function scriptVersion(): ?string
    {
        return $this->installer->installedVersion();
    }

    #[On('sites-changed')]
    #[On('run-started')]
    public function refreshSidebar(): void
    {
        unset($this->favorites, $this->activeRuns);
    }

    public function render(): View
    {
        return view('livewire.sidebar');
    }
}
