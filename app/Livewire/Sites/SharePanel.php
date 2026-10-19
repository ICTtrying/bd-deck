<?php

namespace App\Livewire\Sites;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lokale site tijdelijk delen met een klant via een openbare link (ddev share), zonder testserver.
 */
class SharePanel extends Component
{
    use InteractsWithSites;

    public Site $site;

    public int $minutes = 240;

    /**
     * De run die nu een link maakt; het venster volgt hem tot hij klaar is.
     */
    public ?int $runId = null;

    #[On('open-share')]
    public function open(): void
    {
        $this->resetValidation();
        $this->site->refresh();

        // een link die net gemaakt wordt, blijft zichtbaar als je het venster opnieuw opent
        $this->runId = $this->site->runs()->active()->where('action', WpOpenAction::Share)->latestFirst()->value('id') ?? $this->runId;
        unset($this->run);

        $this->dispatch('open-modal', name: 'share');
    }

    /**
     * @return array<int, string>
     */
    public function durations(): array
    {
        return [
            60 => __('1 uur'),
            240 => __('4 uur'),
            480 => __('1 werkdag (8 uur)'),
            1440 => __('24 uur'),
        ];
    }

    public function start(CommandRunner $runner): void
    {
        $this->validate(['minutes' => ['required', 'integer', Rule::in(WpOpenCommand::SHARE_DURATIONS)]]);

        $this->attempt(function () use ($runner): void {
            $this->runId = $this->queueCommand($runner, WpOpenCommand::share($this->site, $this->minutes))->id;
            unset($this->run);
        });
    }

    #[Computed]
    public function run(): ?CommandRun
    {
        return $this->runId === null ? null : $this->site->runs()->find($this->runId);
    }

    /**
     * Zolang de link gemaakt wordt; daarna meteen de link tonen.
     */
    public function poll(SiteRegistry $registry): void
    {
        unset($this->run);

        if ($this->run === null || $this->run->status->isActive()) {
            return;
        }

        // de job ververst de site pas ná het afronden van de run: niet daarop wachten
        if ($this->run->status === RunStatus::Succeeded) {
            rescue(fn (): ?Site => $registry->refresh($this->site), report: false);
            $this->runId = null;
        }

        $this->site->refresh();
        $this->dispatch('sites-changed');
    }

    public function stop(WpOpen $wpopen, SiteRegistry $registry): void
    {
        $this->attempt(function () use ($wpopen, $registry): void {
            $wpopen->runOrFail(WpOpenCommand::shareStop($this->site)->arguments, 60);
            $registry->refresh($this->site);

            $this->runId = null;
            $this->dispatch('sites-changed');
            $this->dispatch('toast', title: __('Deellink gestopt'), message: __('De link naar :site werkt niet meer.', ['site' => $this->site->name]), tone: 'info');
        });
    }

    /**
     * Kant-en-klaar bericht voor de klant, met een eindtijd die een klant zonder rekenen snapt.
     */
    public function clientMessage(): string
    {
        $expires = $this->site->shareExpiresAt();

        if ($expires === null || $this->site->shareUrl() === null) {
            return '';
        }

        $until = match (true) {
            $expires->isToday() => __('vandaag :time', ['time' => $expires->format('H:i')]),
            $expires->isTomorrow() => __('morgen :time', ['time' => $expires->format('H:i')]),
            default => $expires->translatedFormat('l j F H:i'),
        };

        return __("Hoi,\n\nVia deze link kun je de nieuwe versie van de website bekijken:\n:url\n\nDe link werkt tot :until.", ['url' => $this->site->shareUrl(), 'until' => $until]);
    }

    public function render(): View
    {
        return view('livewire.sites.share-panel');
    }
}
