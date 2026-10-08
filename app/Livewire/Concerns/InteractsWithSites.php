<?php

namespace App\Livewire\Concerns;

use App\Exceptions\DesktopLaunchException;
use App\Exceptions\VaultLockedException;
use App\Exceptions\WpOpenException;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\DesktopLauncher;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use InvalidArgumentException;

/**
 * Snelknoppen en acties die op meerdere schermen terugkomen.
 */
trait InteractsWithSites
{
    public function launch(int $siteId, string $target, DesktopLauncher $launcher, AppSettings $settings, WpOpen $wpopen): void
    {
        $site = Site::query()->findOrFail($siteId);

        $this->attempt(function () use ($site, $target, $launcher, $settings, $wpopen): void {
            match ($target) {
                'local-site' => $launcher->openUrl($this->requireBuilt($site)->localUrl('/')),
                'live-site' => $launcher->openUrl($site->liveUrl('/')),
                // Laravel heeft geen vaste beheerpagina: dan gewoon de site
                'local-admin' => $launcher->openUrl($site->isLaravel() ? $this->requireBuilt($site)->localUrl('/') : ($settings->autoLoginLocal()
                    ? $wpopen->loginUrl($this->requireBuilt($site)->name, live: false)
                    : $site->localUrl('/wp-admin/'))),
                'live-admin' => $launcher->openUrl($site->isLaravel() ? $site->liveUrl('/') : ($settings->autoLoginLive()
                    ? $wpopen->loginUrl($site->name, live: true, user: $site->live_login_user)
                    : $site->liveUrl('/wp-admin/'))),
                'editor' => $launcher->openEditor($this->requireBuilt($site)->wpContentDirectory()),
                'terminal' => $launcher->openTerminal($this->requireBuilt($site)->projectDirectory()),
                'folder' => $launcher->openFolder($this->requireBuilt($site)->projectDirectory()),
                'ssh' => $launcher->runInTerminal(
                    $settings->homeDirectory(),
                    ['bash', $settings->scriptPath(), 'ssh', $site->name],
                    $site->name.' (live)',
                ),
                'hosting' => $launcher->openUrl($site->providerType->dashboardUrl() ?? $site->liveUrl('/')),
                default => throw new InvalidArgumentException(__('Onbekende actie.')),
            };
        });
    }

    public function runQuick(int $siteId, string $action, CommandRunner $runner): void
    {
        $site = Site::query()->findOrFail($siteId);

        $command = match ($action) {
            'build' => WpOpenCommand::build($site),
            'start' => WpOpenCommand::start($site),
            'stop' => WpOpenCommand::stop($site),
            'test' => WpOpenCommand::test($site),
            'updates' => WpOpenCommand::updates($site),
            'cache-local' => WpOpenCommand::cache($site, live: false),
            'cache-live' => WpOpenCommand::cache($site, live: true),
            'pull-code' => WpOpenCommand::pullCode($site),
            'fix' => WpOpenCommand::fix($site),
            default => throw new InvalidArgumentException(__('Onbekende actie.')),
        };

        $this->queueCommand($runner, $command);
    }

    /**
     * @param  array<string, string>  $secrets
     */
    protected function queueCommand(CommandRunner $runner, WpOpenCommand $command, array $secrets = []): CommandRun
    {
        $run = $runner->queue($command, $secrets);

        $this->dispatch('run-started', runId: $run->id);
        $this->dispatch('toast', title: __(':action gestart', ['action' => $run->label]), message: $run->site_name, tone: 'info');

        return $run;
    }

    public function toggleFavorite(int $siteId): void
    {
        $site = Site::query()->findOrFail($siteId);
        $site->update(['is_favorite' => ! $site->is_favorite]);

        $this->dispatch('sites-changed');
    }

    protected function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (WpOpenException|DesktopLaunchException|VaultLockedException|InvalidArgumentException $exception) {
            $this->dispatch('toast', title: __('Dat lukte niet'), message: $exception->getMessage(), tone: 'danger');
        }
    }

    private function requireBuilt(Site $site): Site
    {
        if (! $site->isBuilt()) {
            throw new InvalidArgumentException(__(':site is nog niet lokaal gebouwd. Kies eerst "Lokaal bouwen".', ['site' => $site->name]));
        }

        return $site;
    }
}
