<?php

namespace App\Jobs;

use App\Models\OffsiteBackup;
use App\Services\DesktopNotifier;
use App\Services\Offsite\OffsiteRestorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RestoreOffsiteBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $offsiteBackupId)
    {
        $this->onQueue('offsite');
    }

    public function handle(OffsiteRestorer $restorer, DesktopNotifier $notifier): void
    {
        $backup = OffsiteBackup::query()->find($this->offsiteBackupId);

        if ($backup === null) {
            Cache::forget(OffsiteRestorer::stateKey($this->offsiteBackupId));

            return;
        }

        try {
            $restorer->restore($backup);
        } catch (Throwable $exception) {
            // zichtbaar op het tabblad Back-ups tot iemand het opnieuw probeert
            Cache::put(OffsiteRestorer::stateKey($backup->id), ['state' => 'failed', 'error' => $exception->getMessage()], now()->addDay());
            $notifier->notice(__('Back-up terughalen mislukt'), $backup->site_name.' — '.$exception->getMessage());

            return;
        }

        Cache::forget(OffsiteRestorer::stateKey($backup->id));
        $notifier->notice(__('Back-up teruggehaald uit de cloud'), __(':site — je kunt hem nu terugzetten.', ['site' => $backup->site_name]));
    }
}
