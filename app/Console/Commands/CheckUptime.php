<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\UptimeMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uptime:check {--all : Meet alle sites, ook als ze nog niet aan de beurt zijn}')]
#[Description('Meet of de live-sites bereikbaar zijn')]
class CheckUptime extends Command
{
    public function handle(UptimeMonitor $monitor): int
    {
        $sites = $this->option('all')
            ? Site::query()->where('uptime_enabled', true)->orderBy('id')->get()
            : $monitor->due();

        $this->components->info(trans_choice(':count site gemeten.|:count sites gemeten.', $monitor->check($sites)));

        return self::SUCCESS;
    }
}
