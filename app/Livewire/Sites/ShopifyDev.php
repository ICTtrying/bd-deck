<?php

namespace App\Livewire\Sites;

use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\ShopifyDevLog;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dev server van een Shopify-project: met één klik starten of stoppen, de adressen en het live log.
 */
class ShopifyDev extends Component
{
    use InteractsWithSites;

    public Site $site;

    protected ShopifyDevLog $devLog;

    public function boot(ShopifyDevLog $devLog): void
    {
        $this->devLog = $devLog;
    }

    #[On('sites-changed')]
    public function refreshSite(): void
    {
        $this->site->refresh();
        unset($this->activeRun, $this->log);
    }

    public function toggle(CommandRunner $runner): void
    {
        if ($this->activeRun !== null) {
            return;
        }

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, $this->site->isRunning() ? WpOpenCommand::stop($this->site) : WpOpenCommand::start($this->site));
            unset($this->activeRun);
        });
    }

    /**
     * Starten en daarna meteen de preview openen; draait hij al, dan opent de preview gewoon.
     */
    public function startAndOpen(CommandRunner $runner): void
    {
        $this->attempt(function () use ($runner): void {
            if ($this->site->isRunning()) {
                return;
            }

            $run = $this->queueCommand($runner, WpOpenCommand::start($this->site));
            Cache::put('shopify-open-after-start:'.$run->id, 'preview', now()->addMinutes(15));
            unset($this->activeRun);
        });
    }

    /**
     * De start- of stoprun die nu loopt.
     */
    #[Computed]
    public function activeRun(): ?CommandRun
    {
        return $this->site->runs()->active()->whereIn('action', [WpOpenAction::ShopifyDevStart, WpOpenAction::ShopifyDevStop])->latestFirst()->first();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function log(): array
    {
        return $this->devLog->tail($this->site);
    }

    /**
     * Kant-en-klaar bericht voor de klant met de deelbare previewlink.
     */
    public function clientMessage(): string
    {
        $url = $this->site->devShareUrl();

        if ($url === null) {
            return '';
        }

        return __("Hoi,\n\nVia deze link kun je de nieuwe versie van de webshop bekijken:\n:url\n\nHet is een voorbeeld; er verandert niets aan de echte winkel.", ['url' => $url]);
    }

    public function render(): View
    {
        return view('livewire.sites.shopify-dev');
    }
}
