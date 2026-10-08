<?php

use App\Services\DesktopLauncher;
use Illuminate\Support\Facades\Process;

it('opent links in de desktop-app op Linux met xdg-open en zonder AppImage-omgeving', function (): void {
    config(['nativephp-internal.running' => true]);
    Process::fake();
    putenv('APPDIR=/tmp/.mount_BD-Deck');
    putenv('LD_LIBRARY_PATH=/tmp/.mount_BD-Deck/usr/lib:');

    try {
        app(DesktopLauncher::class)->openUrl('https://klant.ddev.site/?wpopen-login=abc');
    } finally {
        putenv('APPDIR');
        putenv('LD_LIBRARY_PATH');
    }

    Process::assertRan(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://klant.ddev.site/?wpopen-login=abc']
        && $process->environment['APPDIR'] === false
        && $process->environment['LD_LIBRARY_PATH'] === false);
})->skip(PHP_OS_FAMILY !== 'Linux', 'alleen op Linux');
