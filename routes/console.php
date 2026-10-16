<?php

use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('uptime:check')->everyMinute()->withoutOverlapping(10);

// metingen en afgesloten storingen ouder dan 30 dagen
Schedule::command('model:prune', ['--model' => [UptimeCheck::class, UptimeIncident::class]])->daily();

// de app staat niet de hele nacht open: elk uur kijken of de laatste controle ouder is dan een dag
Schedule::command('updates:check')->hourly()->withoutOverlapping(30);
