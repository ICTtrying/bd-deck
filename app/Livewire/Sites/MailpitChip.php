<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\SiteLinks;
use App\Services\WpOpen\WpOpen;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Mailpit op de brug, met het aantal ongelezen testmails. Laadt los van de pagina: Mailpit kan even niet antwoorden.
 */
class MailpitChip extends Component
{
    use InteractsWithSites;

    public Site $site;

    protected SiteLinks $links;

    public function boot(SiteLinks $links): void
    {
        $this->links = $links;
    }

    /**
     * Ongelezen mails volgens de API van Mailpit; null als de site niet draait of Mailpit niet antwoordt.
     */
    #[Computed]
    public function unread(): ?int
    {
        if (! $this->site->isRunning()) {
            return null;
        }

        // DDEV gebruikt een eigen, lokaal certificaat; dit verkeer blijft op deze computer
        return rescue(function (): ?int {
            $unread = Http::withoutVerifying()->connectTimeout(1)->timeout(2)->acceptJson()
                ->get($this->links->mailpit($this->site).'/api/v1/messages', ['limit' => 1])
                ->throw()
                ->json('unread');

            return is_numeric($unread) ? (int) $unread : null;
        }, null, report: false);
    }

    public function sendTestMail(WpOpen $wpopen): void
    {
        $this->attempt(function () use ($wpopen): void {
            $wpopen->runOrFail(['mailpit', $this->site->name, '--test'], 120);

            unset($this->unread);
            $this->dispatch('toast', title: __('Testmail verstuurd'), message: __('Open Mailpit om hem te zien; er is niets echt verstuurd.'), tone: 'success');
        });
    }

    public function placeholder(): string
    {
        return '<div><span class="inline-flex h-7 items-center gap-1.5 rounded-md bg-surface/70 px-2 text-[0.8125rem] font-medium opacity-60">Mailpit</span></div>';
    }

    public function render(): View
    {
        return view('livewire.sites.mailpit-chip');
    }
}
