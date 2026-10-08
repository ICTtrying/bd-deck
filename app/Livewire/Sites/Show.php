<?php

namespace App\Livewire\Sites;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    public bool $upgradeLive = false;

    public bool $upgradeMajor = false;

    /**
     * Aangevinkte pakketten uit de lijst, als 'composer:naam' of 'npm:naam'.
     *
     * @var list<string>
     */
    public array $selectedPackages = [];

    /**
     * Pakketten die op bevestiging wachten voor de losse update.
     *
     * @var list<string>
     */
    public array $upgradePackages = [];

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
            'pull-uploads' => [
                'title' => __('Uploads ophalen van live'),
                'body' => __('Alle uploads (afbeeldingen, pdf\'s) worden van live naar je computer gekopieerd. Normaal komen ze via de live server binnen; voor verhuizen of offline werken moeten ze echt lokaal staan. Dit kan even duren. Live verandert niet.'),
                'button' => __('Uploads ophalen'),
                'danger' => false,
                'typed' => false,
            ],
            'fix-plugins' => $this->site->isLaravel() ? [
                'title' => __('Site repareren'),
                'body' => __('Zet de lokale .env, de storage-mappen, Composer-pakketten, de storage-link en de caches recht. Live verandert niet.'),
                'button' => __('Repareren'),
                'danger' => false,
                'typed' => false,
            ] : [
                'title' => __('Repareren en plugins uitzetten'),
                'body' => __('Herstelt prefix, URL\'s, uploads-proxy en kapotte plugins, en zet lokaal cache-, beveiligings-, back-up- en SMTP-plugins uit. Live verandert niet.'),
                'button' => __('Repareren'),
                'danger' => false,
                'typed' => false,
            ],
            'upgrade' => [
                'title' => __('Pakketten bijwerken'),
                'body' => __('Eerst worden de lokale database en git-repo bewaard. Daarna werkt BD Deck lokaal alle Composer- en npm-pakketten bij (standaard binnen de versies uit composer.json en package.json; met de schakelaar hieronder ook nieuwe hoofdversies), bouwt de assets en controleert of de site nog werkt. Gaat er iets mis, dan wordt alles teruggezet. De updates komen als losse commit op dev.'),
                'button' => __('Bijwerken'),
                'danger' => false,
                'typed' => false,
            ],
            ...($this->pendingPackages()->isEmpty() ? [] : ['upgrade-package' => [
                'title' => $this->pendingPackages()->count() === 1
                    ? __(':name bijwerken', ['name' => $this->pendingPackages()->first()['name']])
                    : __(':count pakketten bijwerken', ['count' => $this->pendingPackages()->count()]),
                'body' => __('Eerst worden de lokale database en git-repo bewaard; daarna werkt BD Deck alleen :packages bij, controleert of de site nog werkt en zet bij een fout alles terug. De update komt als losse commit op dev.', ['packages' => $this->pendingPackages()->map(fn (array $item): string => $item['name'].' ('.$item['version'].' → '.$item['update_version'].')')->join(', ', __(' en '))])
                    .($this->pendingPackages()->contains('major', true) ? ' '.__('Let op: er zit een nieuwe hoofdversie bij, die kan je code breken.') : ''),
                'button' => __('Bijwerken'),
                'danger' => false,
                'typed' => false,
            ]]),
            ...($this->recentDeploy === null ? [] : ['rollback-push' => [
                'title' => __('Live zetten terugdraaien'),
                'body' => $this->site->isLaravel()
                    ? __('De bestanden op live gaan terug naar de staat van vóór back-up :id. Lokaal blijft je werk op dev staan. Composer-pakketten en migraties op live worden niet teruggedraaid.', ['id' => $this->recentDeploy->backupId()])
                    : __('De bestanden op live gaan terug naar de staat van vóór back-up :id. Lokaal blijft je werk op dev staan, dus je kunt het later opnieuw live zetten.', ['id' => $this->recentDeploy->backupId()]),
                'button' => __('Terugdraaien'),
                'danger' => true,
                'typed' => false,
            ]]),
            'cache-live' => [
                'title' => __('Live cache legen'),
                'body' => __('Leegt de object- en paginacache op live (Hummingbird, WPMU DEV-servercache en gangbare cacheplugins). Bezoekers krijgen daarna een paar seconden een iets tragere eerste paginaweergave.'),
                'button' => __('Live cache legen'),
                'danger' => false,
                'typed' => false,
            ],
        ];
    }

    /**
     * De te bevestigen pakketten, alleen als ze echt in de lijst met beschikbare updates staan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingPackages(): Collection
    {
        return collect($this->upgradePackages)
            ->map(function (string $package): ?array {
                [$kind, $name] = array_pad(explode(':', $package, 2), 2, '');

                return collect($this->site->updates[$kind] ?? [])->firstWhere('name', $name);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  list<string>  $packages
     */
    private function confirmPackages(array $packages): void
    {
        $this->upgradePackages = $packages;

        if ($this->pendingPackages()->count() !== count($packages) || $packages === []) {
            $this->upgradePackages = [];

            return;
        }

        $this->confirm('upgrade-package');
    }

    public function confirmPackage(string $kind, string $name): void
    {
        $this->confirmPackages([$kind.':'.$name]);
    }

    public function confirmSelectedPackages(): void
    {
        $this->confirmPackages($this->selectedPackages);
    }

    public function confirm(string $action): void
    {
        if (! array_key_exists($action, $this->confirmations())) {
            return;
        }

        $this->confirming = $action;
        $this->confirmText = '';
        $this->dispatch('open-modal', name: 'confirm-action');
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
                'pull-uploads' => WpOpenCommand::pullUploads($this->site),
                'fix-plugins' => WpOpenCommand::fix($this->site, disableEnvironmentPlugins: ! $this->site->isLaravel()),
                'cache-live' => WpOpenCommand::cache($this->site, live: true),
                'upgrade' => WpOpenCommand::upgrade($this->site, live: $this->upgradeLive, major: $this->upgradeMajor),
                'upgrade-package' => WpOpenCommand::upgrade($this->site, live: $this->upgradeLive, packages: $this->upgradePackages),
                'rollback-push' => WpOpenCommand::restore($this->site, (string) $this->recentDeploy?->backupId(), ['live-files']),
                default => throw new InvalidArgumentException(__('Onbekende actie.')),
            });
        });

        $this->confirming = null;
        $this->upgradePackages = [];
        $this->selectedPackages = [];
        $this->dispatch('close-modal', name: 'confirm-action');
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
            unset($this->lastRun, $this->recentDeploy);
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
     * Net live gezet: dan staan "bekijken" en "terugdraaien" direct klaar.
     */
    #[Computed]
    public function recentDeploy(): ?CommandRun
    {
        $run = $this->lastRun;

        $deployed = $run?->status === RunStatus::Succeeded
            && in_array($run->action, [WpOpenAction::Push, WpOpenAction::Upgrade], true)
            && $run->finished_at?->gt(now()->subHours(2))
            && $run->backupId() !== null;

        return $deployed ? $run : null;
    }

    /**
     * Richting van de animatie op de brug.
     */
    public function flow(): ?string
    {
        return match ($this->activeRun?->action) {
            WpOpenAction::Push => 'push',
            WpOpenAction::PullCode, WpOpenAction::PullDatabase, WpOpenAction::PullUploads, WpOpenAction::Build, WpOpenAction::Rebuild => 'pull',
            WpOpenAction::Migrate => 'push',
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
