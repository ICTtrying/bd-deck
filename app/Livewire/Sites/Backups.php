<?php

namespace App\Livewire\Sites;

use App\Enums\OffsiteStatus;
use App\Exceptions\OffsiteException;
use App\Jobs\RestoreOffsiteBackup;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\OffsiteBackup;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\DesktopLauncher;
use App\Services\Offsite\OffsiteRestorer;
use App\Services\Offsite\OffsiteSync;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Backups extends Component
{
    use InteractsWithSites;

    public Site $site;

    public bool $includeLocal = true;

    public bool $includeLiveDatabase = false;

    public bool $includeLiveFiles = false;

    public ?string $restoring = null;

    /**
     * @var list<string>
     */
    public array $restoreParts = [];

    public string $confirmText = '';

    protected WpOpen $wpopen;

    public function boot(WpOpen $wpopen): void
    {
        $this->wpopen = $wpopen;
    }

    /**
     * @return list<array{id: string, kind: string, created: string, size: int, parts: list<string>}>
     */
    #[Computed]
    public function backups(): array
    {
        return rescue(fn (): array => $this->wpopen->backups($this->site->name), [], report: false);
    }

    /**
     * @return array<string, string>
     */
    public function kindLabels(): array
    {
        return [
            'push' => __('Voor een push'),
            'manual' => __('Handmatig'),
            'rebuild' => __('Voor opnieuw migreren'),
            'pull-db' => __('Voor database ophalen'),
            'import-db' => __('Voor database-import'),
            'pre-restore' => __('Voor terugzetten'),
            'remove' => __('Voor verwijderen'),
            'upgrade' => __('Voor pakketten bijwerken'),
            'safe-update' => __('Voor veilig bijwerken'),
            'migrate' => __('Voor verhuizen'),
            'domain' => __('Voor domein omzetten'),
            'go-live' => __('Voor lokale adressen omzetten'),
        ];
    }

    /**
     * @return array<string, array{label: string, restore: ?string, live: bool}>
     */
    public function partLabels(): array
    {
        return [
            'local-db.sql.gz' => ['label' => __('Lokale database'), 'restore' => 'local-db', 'live' => false],
            'wp-content.bundle' => ['label' => __('Lokale git-repo'), 'restore' => null, 'live' => false],
            'live-db.sql.gz' => ['label' => __('Live database'), 'restore' => $this->site->mode->hasShell() ? 'live-db' : null, 'live' => true],
            'live-files' => ['label' => __('Live-bestanden van vóór de push'), 'restore' => 'live-files', 'live' => true],
            'live-full' => ['label' => __('Live thema\'s en plugins'), 'restore' => null, 'live' => true],
        ];
    }

    public function create(CommandRunner $runner): void
    {
        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::backup(
            $this->site,
            local: $this->includeLocal && $this->site->isBuilt(),
            liveDatabase: $this->includeLiveDatabase,
            liveFiles: $this->includeLiveFiles,
        )));
    }

    public function startRestore(string $backupId): void
    {
        $backup = collect($this->backups)->firstWhere('id', $backupId);

        if ($backup === null) {
            return;
        }

        $this->restoring = $backupId;
        $this->restoreParts = collect($backup['parts'])->map(fn (string $part): ?string => $this->partLabels()[$part]['restore'] ?? null)->filter()->values()->all();
        $this->confirmText = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'restore');
    }

    public function restore(CommandRunner $runner): void
    {
        $touchesLive = array_intersect($this->restoreParts, ['live-db', 'live-files']) !== [];

        if ($touchesLive && $this->confirmText !== $this->site->name) {
            $this->addError('confirmText', __('Typ de naam van de site om te bevestigen.'));

            return;
        }

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::restore($this->site, (string) $this->restoring, $this->restoreParts));
            $this->dispatch('close-modal', name: 'restore');
        });
    }

    public function openFolder(DesktopLauncher $launcher, AppSettings $settings): void
    {
        $this->attempt(fn () => $launcher->openFolder($settings->backupsDirectory().'/'.$this->site->data('slug')));
    }

    public function delete(string $backupId, AppSettings $settings): void
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $backupId) || collect($this->backups)->doesntContain('id', $backupId)) {
            return;
        }

        File::deleteDirectory($settings->backupsDirectory().'/'.$this->site->data('slug').'/'.$backupId);
        unset($this->backups);

        $this->dispatch('toast', title: __('Back-up verwijderd'), message: $backupId, tone: 'success');
    }

    #[On('sites-changed')]
    public function refreshBackups(): void
    {
        unset($this->backups, $this->cloudBackups);
    }

    public function offsiteEnabled(): bool
    {
        return app(AppSettings::class)->offsite()->enabled;
    }

    /**
     * @return Collection<string, OffsiteBackup>
     */
    #[Computed]
    public function cloudBackups(): Collection
    {
        return OffsiteBackup::query()->forSite((string) $this->site->data('slug'))->newestFirst()->get()->keyBy('backup_id');
    }

    /**
     * Back-ups die alleen in de cloud staan, bijvoorbeeld op een nieuwe laptop.
     *
     * @return Collection<int, OffsiteBackup>
     */
    public function cloudOnly(): Collection
    {
        $local = collect($this->backups)->pluck('id')->all();

        return $this->cloudBackups->filter(fn (OffsiteBackup $backup): bool => $backup->status === OffsiteStatus::Uploaded && ! in_array($backup->backup_id, $local, true))->values();
    }

    public function cloudStatus(string $backupId): OffsiteStatus
    {
        return $this->cloudBackups->get($backupId)?->status ?? OffsiteStatus::Deleted;
    }

    /**
     * @return array{state: string, error: ?string}|null
     */
    public function restoreState(OffsiteBackup $backup): ?array
    {
        return OffsiteRestorer::state($backup->id);
    }

    /**
     * Alleen verversen zolang er iets op de achtergrond loopt.
     */
    public function isCloudBusy(): bool
    {
        if (! $this->offsiteEnabled()) {
            return false;
        }

        return $this->cloudBackups->contains(fn (OffsiteBackup $backup): bool => $backup->status->isBusy() || ($this->restoreState($backup)['state'] ?? null) === 'running');
    }

    public function fetchFromCloud(int $offsiteBackupId): void
    {
        $backup = $this->cloudOnly()->firstWhere('id', $offsiteBackupId);

        if ($backup === null) {
            return;
        }

        if (! $backup->isEncryptedWith(app(AppSettings::class)->offsite()->keyId)) {
            $this->dispatch('toast', title: __('Ander versleutelwachtwoord'), message: __('Deze back-up is versleuteld met een eerder wachtwoord. Zet dat wachtwoord terug in Instellingen om hem terug te halen.'), tone: 'danger');

            return;
        }

        OffsiteRestorer::markQueued($backup->id);
        RestoreOffsiteBackup::dispatch($backup->id);
        $this->refreshBackups();

        $this->dispatch('toast', title: __('Back-up wordt teruggehaald'), message: __('Daarna kun je hem gewoon terugzetten.'), tone: 'success');
    }

    public function searchCloud(OffsiteSync $sync): void
    {
        try {
            $found = $sync->discover((string) $this->site->data('slug'), $this->site->name);
        } catch (OffsiteException $exception) {
            $this->dispatch('toast', title: __('Cloud doorzoeken mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->refreshBackups();
        $this->dispatch('toast', title: $found > 0 ? trans_choice(':count back-up gevonden in de cloud|:count back-ups gevonden in de cloud', $found) : __('Geen nieuwe back-ups gevonden in de cloud'), tone: 'success');
    }

    public function render(): View
    {
        return view('livewire.sites.backups');
    }
}
