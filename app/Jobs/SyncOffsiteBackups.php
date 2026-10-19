<?php

namespace App\Jobs;

use App\Services\Offsite\OffsiteSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Eigen wachtrij: een upload van gigabytes mag bouwen en pushen niet ophouden.
 */
class SyncOffsiteBackups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * Zolang er een sync loopt of wacht, plant de scheduler geen tweede in.
     */
    public int $uniqueFor = 21600;

    public function __construct()
    {
        $this->onQueue('offsite');
    }

    public function handle(OffsiteSync $sync): void
    {
        $sync->run();
    }
}
