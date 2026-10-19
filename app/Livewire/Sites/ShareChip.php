<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use Carbon\CarbonInterval;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Delen" op de brug; staat er een link, dan met de resterende tijd. Ververst zelf, zodat de sitepagina niet hoeft.
 */
class ShareChip extends Component
{
    public Site $site;

    #[On('sites-changed')]
    public function refreshSite(): void
    {
        $this->site->refresh();
    }

    /**
     * Resterende tijd, kort: "3u 12m".
     */
    public function remaining(): string
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($this->site->shareExpiresAt(), absolute: true)));

        return CarbonInterval::minutes($minutes)->cascade()->forHumans(short: true, parts: 2);
    }

    public function render(): View
    {
        return view('livewire.sites.share-chip');
    }
}
