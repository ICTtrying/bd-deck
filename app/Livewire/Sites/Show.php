<?php

namespace App\Livewire\Sites;

use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use InteractsWithSites;
    use WithPagination;

    public Site $site;

    #[Url(as: 'tab', except: 'overzicht')]
    public string $tab = 'overzicht';

    public string $notes = '';

    /**
     * Actie die op bevestiging wacht; zie confirmations().
     */
    public ?string $confirming = null;

    public string $confirmText = '';

    public bool $pullBeforeReset = false;

    public function mount(Site $site, SiteRegistry $registry): void
    {
        $this->site = rescue(fn () => $registry->refresh($site), $site, report: false) ?? $site;
        $this->notes = (string) $this->site->notes;
    }

    /**
     * @return array<string, array{title: string, body: string, button: string, danger: bool, typed: bool}>
     */
    public function confirmations(): array
    {
        return [
            'rebuild' => [
                'title' => __('Lokaal opnieuw migreren'),
                'body' => __('De lokale site wordt verwijderd en opnieuw opgebouwd vanaf live: code, database en DDEV-omgeving. Je huidige lokale database en git-repo worden eerst als back-up bewaard. Live verandert niet.'),
                'button' => __('Opnieuw migreren'),
                'danger' => true,
                'typed' => false,
            ],
            'reset' => [
                'title' => __('Terugzetten naar main'),
                'body' => __('Lokale wijzigingen op dev worden vervangen door main, de laatst bekende live-staat. Je huidige werk blijft bewaard in een backup-branch. Live verandert niet.'),
                'button' => __('Terugzetten'),
                'danger' => true,
                'typed' => false,
            ],
            'pull-database' => [
                'title' => __('Database ophalen van live'),
                'body' => __('De lokale database wordt overschreven met een verse kopie van live. De huidige lokale database wordt eerst bewaard.'),
                'button' => __('Database ophalen'),
                'danger' => false,
                'typed' => false,
            ],
            'fix-plugins' => [
                'title' => __('Repareren en plugins uitzetten'),
                'body' => __('Herstelt prefix, URL\'s, uploads-proxy en kapotte plugins, en zet lokaal cache-, beveiligings-, back-up- en SMTP-plugins uit. Live verandert niet.'),
                'button' => __('Repareren'),
                'danger' => false,
                'typed' => false,
            ],
            'cache-live' => [
                'title' => __('Live cache legen'),
                'body' => __('Leegt de object- en paginacache op live (Hummingbird, WPMU DEV-servercache en gangbare cacheplugins). Bezoekers krijgen daarna een paar seconden een iets tragere eerste paginaweergave.'),
                'button' => __('Live cache legen'),
                'danger' => false,
                'typed' => false,
            ],
        ];
    }

    public function confirm(string $action): void
    {
        if (! array_key_exists($action, $this->confirmations())) {
            return;
        }

        $this->confirming = $action;
        $this->confirmText = '';
        $this->dispatch('open-modal', 'confirm-action');
    }

    public function proceed(CommandRunner $runner): void
    {
        $action = $this->confirming;
        $confirmation = $this->confirmations()[$action] ?? null;

        if ($confirmation === null) {
            return;
        }

        if ($confirmation['typed'] && $this->confirmText !== $this->site->name) {
            $this->addError('confirmText', __('Typ de naam van de site om te bevestigen.'));

            return;
        }

        $this->attempt(function () use ($action, $runner): void {
            $this->queueCommand($runner, match ($action) {
                'rebuild' => WpOpenCommand::rebuild($this->site),
                'reset' => WpOpenCommand::reset($this->site, $this->pullBeforeReset),
                'pull-database' => WpOpenCommand::pullDatabase($this->site),
                'fix-plugins' => WpOpenCommand::fix($this->site, disableEnvironmentPlugins: true),
                'cache-live' => WpOpenCommand::cache($this->site, live: true),
                default => throw new InvalidArgumentException(__('Onbekende actie.')),
            });
        });

        $this->confirming = null;
        $this->dispatch('close-modal', 'confirm-action');
        unset($this->activeRun);
    }

    public function run(string $action, CommandRunner $runner): void
    {
        $this->runQuick($this->site->id, $action, $runner);
        unset($this->activeRun);
    }

    public function cancelRun(int $runId, CommandRunner $runner): void
    {
        $run = $this->site->runs()->findOrFail($runId);
        $runner->cancel($run);

        $this->dispatch('toast', title: __('Wordt gestopt'), message: $run->label, tone: 'info');
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $this->site->update(['notes' => $this->notes]);
    }

    #[On('run-started')]
    public function runStarted(): void
    {
        unset($this->activeRun);
    }

    /**
     * Wordt elke paar seconden aangeroepen zolang er iets loopt.
     */
    public function poll(): void
    {
        $wasActive = $this->activeRun !== null;
        unset($this->activeRun);

        if ($wasActive && $this->activeRun === null) {
            $this->site->refresh();
            $this->dispatch('sites-changed');
        }
    }

    #[Computed]
    public function activeRun(): ?CommandRun
    {
        return $this->site->runs()->active()->latestFirst()->first();
    }

    #[Computed]
    public function lastRun(): ?CommandRun
    {
        return $this->site->runs()->latestFirst()->first();
    }

    /**
     * Richting van de animatie op de brug.
     */
    public function flow(): ?string
    {
        return match ($this->activeRun?->action) {
            WpOpenAction::Push => 'push',
            WpOpenAction::PullCode, WpOpenAction::PullDatabase, WpOpenAction::Build, WpOpenAction::Rebuild => 'pull',
            default => null,
        };
    }

    /**
     * @return LengthAwarePaginator<int, CommandRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return $this->site->runs()->latestFirst()->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.sites.show')->title($this->site->name);
    }
}
