<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DesktopLauncher;
use App\Services\DesktopNotifier;
use App\Services\SiteLinks;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Een gestopte lokale site moet eerst starten (DDEV, 10 tot 20 seconden). Op de achtergrond,
 * anders staat de hele app zolang stil; de browser opent zodra de site klaar is.
 */
class OpenLocalSite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $siteId, public string $target) {}

    public function handle(WpOpen $wpopen, SiteLinks $links, DesktopLauncher $launcher, SiteRegistry $registry, DesktopNotifier $notifier): void
    {
        $site = Site::query()->find($this->siteId);

        if ($site === null) {
            return;
        }

        try {
            $wpopen->runOrFail(['start', $site->name], $this->timeout - 30);

            $site = $registry->refresh($site) ?? $site;
            $launcher->openUrl($links->local($site, $this->target));
        } catch (Throwable $exception) {
            $notifier->notice(__('Lokale site openen mislukt'), $site->name.' — '.Str::limit(trim($exception->getMessage()), 140));
        }
    }
}
