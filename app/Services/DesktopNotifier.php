<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\CommandRun;
use Native\Desktop\Notification;

final class DesktopNotifier
{
    public function __construct(private readonly AppSettings $settings) {}

    public function runFinished(CommandRun $run): void
    {
        if (! $this->settings->notificationsEnabled() || ! config('nativephp-internal.running')) {
            return;
        }

        $title = match ($run->status) {
            RunStatus::Succeeded => __(':action gelukt', ['action' => $run->label]),
            RunStatus::Cancelled => __(':action gestopt', ['action' => $run->label]),
            default => __(':action mislukt', ['action' => $run->label]),
        };

        // een melding mag een afgeronde actie nooit alsnog laten mislukken
        rescue(fn () => Notification::new()
            ->title($title)
            ->message(trim(($run->site_name ? $run->site_name.' — ' : '').($run->lastMessage() ?? '')))
            ->show(), report: false);
    }
}
