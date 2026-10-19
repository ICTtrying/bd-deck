<?php

namespace App\Console\Commands;

use App\Jobs\SyncOffsiteBackups;
use App\Services\AppSettings;
use App\Services\Offsite\OffsiteSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:offsite {--now : Direct uitvoeren in plaats van via de wachtrij}')]
#[Description('Zet nieuwe back-ups versleuteld in de cloud en ruimt oude op volgens de bewaarregels')]
class SyncOffsiteBackupsCommand extends Command
{
    public function handle(AppSettings $settings, OffsiteSync $sync): int
    {
        if (! $settings->offsite()->isActive()) {
            $this->components->info(__('Back-ups in de cloud staan uit of zijn niet volledig ingesteld.'));

            return self::SUCCESS;
        }

        if (! $this->option('now')) {
            SyncOffsiteBackups::dispatch();
            $this->components->info(__('Synchronisatie staat in de wachtrij.'));

            return self::SUCCESS;
        }

        $result = $sync->run();
        $this->components->info(__(':uploaded geüpload, :failed mislukt, :pruned opgeruimd in de cloud, :cleaned lokaal opgeruimd.', $result));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
