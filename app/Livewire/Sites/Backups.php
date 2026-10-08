<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\DesktopLauncher;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
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
        $this->dispatch('open-modal', 'restore');
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
            $this->dispatch('close-modal', 'restore');
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
        unset($this->backups);
    }

    public function render(): View
    {
        return view('livewire.sites.backups');
    }
}
