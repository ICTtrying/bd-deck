<?php

namespace App\Console\Commands;

use App\Services\AppSettings;
use App\Services\UpdateChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('updates:check {--force : Ook sites die de afgelopen 24 uur al gecontroleerd zijn}')]
#[Description('Controleert op de achtergrond of er updates beschikbaar zijn')]
class CheckUpdates extends Command
{
    public function handle(AppSettings $settings, UpdateChecker $checker): int
    {
        if (! $settings->dailyUpdateCheck() && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $sites = $this->option('force') ? $checker->checkable() : $checker->stale();

        $this->components->info(trans_choice(':count site in de wachtrij gezet.|:count sites in de wachtrij gezet.', $checker->queue($sites)));

        return self::SUCCESS;
    }
}
