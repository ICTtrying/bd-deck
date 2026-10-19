<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\CommandRun;
use App\Models\Site;
use Native\Desktop\Notification;

class DesktopNotifier
{
    public function __construct(private readonly AppSettings $settings) {}

    public function runFinished(CommandRun $run): void
    {
        $title = match ($run->status) {
            RunStatus::Succeeded => __(':action gelukt', ['action' => $run->label]),
            RunStatus::Cancelled => __(':action gestopt', ['action' => $run->label]),
            default => __(':action mislukt', ['action' => $run->label]),
        };

        $this->notice($title, trim(($run->site_name ? $run->site_name.' — ' : '').($run->lastMessage() ?? '')));
    }

    public function siteDown(Site $site, ?string $reason): void
    {
        $this->notice(__(':site is offline', ['site' => $site->name]), $reason ?? $site->liveUrl('/'));
    }

    public function siteRecovered(Site $site, int $downMinutes): void
    {
        $this->notice(__(':site is weer online', ['site' => $site->name]), trans_choice('Was :count minuut offline|Was :count minuten offline', max(1, $downMinutes)));
    }

    public function updatesFound(Site $site, int $count): void
    {
        $this->notice(__('Nieuwe updates voor :site', ['site' => $site->name]), trans_choice(':count update beschikbaar|:count updates beschikbaar', $count));
    }

    public function notice(string $title, string $message): void
    {
        if (! $this->settings->notificationsEnabled() || ! config('nativephp-internal.running')) {
            return;
        }

        // een melding mag een afgeronde actie nooit alsnog laten mislukken
        rescue(fn () => Notification::new()->title($title)->message($message)->show(), report: false);
    }

    public function offsiteBackupFailed(int $count, string $reason): void
    {
        $this->notice(trans_choice(':count back-up kon niet naar de cloud|:count back-ups konden niet naar de cloud', $count), $reason);
    }
}
