<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\AppSettings;
use App\Services\UptimeMonitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Uptime extends Component
{
    public Site $site;

    public function check(UptimeMonitor $monitor): void
    {
        $monitor->check(new Collection([$this->site]));

        $this->refreshSite();
        $this->dispatch('sites-changed');
        $this->dispatch('toast', title: __('Gemeten'), message: $this->site->uptime->label(), tone: $this->site->uptime->tone() === 'danger' ? 'danger' : 'success');
    }

    public function toggle(UptimeMonitor $monitor): void
    {
        if ($this->site->uptime_enabled) {
            $monitor->stopMonitoring($this->site);
            $this->refreshSite();
        } else {
            $this->site->update(['uptime_enabled' => true]);
            $this->check($monitor);

            return;
        }

        $this->dispatch('sites-changed');
    }

    /**
     * Oudste meting links, nieuwste rechts.
     *
     * @return Collection<int, UptimeCheck>
     */
    #[Computed]
    public function recentChecks(): Collection
    {
        return $this->site->uptimeChecks()->latestFirst()->limit(60)->get()->reverse()->values();
    }

    /**
     * @return Collection<int, UptimeIncident>
     */
    #[Computed]
    public function incidents(): Collection
    {
        return $this->site->uptimeIncidents()->latestFirst()->limit(8)->get();
    }

    private function refreshSite(): void
    {
        $this->site->refresh();
        unset($this->recentChecks, $this->incidents);
    }

    public function render(AppSettings $settings): View
    {
        return view('livewire.sites.uptime', ['globallyEnabled' => $settings->uptimeEnabled(), 'interval' => $settings->uptimeIntervalMinutes()]);
    }
}
