<?php

use App\Jobs\OpenLocalSite;
use App\Livewire\Dashboard;
use App\Models\Site;
use App\Models\User;
use App\Services\DesktopLauncher;
use App\Services\DesktopNotifier;
use App\Services\SiteLinks;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

describe('lokale site openen', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        $this->actingAs(User::factory()->owner()->create());
    });

    it('opent een draaiende lokale site direct', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);
        Process::fake(['*' => Process::result('https://klant.ddev.site/?wpopen-login=abc')]);

        Livewire::test(Dashboard::class)->call('launch', $site->id, 'local-admin');

        Queue::assertNotPushed(OpenLocalSite::class);
        Process::assertRan(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://klant.ddev.site/?wpopen-login=abc']);
    });

    it('start een gestopte lokale site op de achtergrond in plaats van de app te laten wachten', function (): void {
        $site = Site::factory()->built()->snapshotState(['ddev_status' => 'paused'])->create(['name' => 'klant']);
        Process::fake();

        Livewire::test(Dashboard::class)
            ->call('launch', $site->id, 'local-admin')
            ->assertDispatched('toast', title: 'Lokale site wordt gestart…');

        Queue::assertPushedOn('quick', OpenLocalSite::class, fn (OpenLocalSite $job): bool => $job->siteId === $site->id && $job->target === 'local-admin');
        Process::assertNothingRan();
    });

    it('start de site en opent daarna de inloglink', function (): void {
        $site = Site::factory()->built()->snapshotState(['ddev_status' => 'paused'])->create(['name' => 'klant']);
        Process::fake([
            '*start*' => Process::result('ok'),
            '*info*' => Process::result(json_encode([...$site->snapshot, 'ddev_status' => 'running'])),
            '*login*' => Process::result('https://klant.ddev.site/?wpopen-login=abc'),
            '*' => Process::result(''),
        ]);

        (new OpenLocalSite($site->id, 'local-admin'))->handle(
            app(WpOpen::class), app(SiteLinks::class), app(DesktopLauncher::class), app(SiteRegistry::class), app(DesktopNotifier::class),
        );

        Process::assertRan(fn ($process): bool => in_array('start', $process->command, true));
        Process::assertRan(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://klant.ddev.site/?wpopen-login=abc']);
        expect($site->fresh()->isRunning())->toBeTrue();
    });

    it('meldt het als starten mislukt', function (): void {
        $site = Site::factory()->built()->snapshotState(['ddev_status' => 'paused'])->create(['name' => 'klant']);
        Process::fake(['*' => Process::result(errorOutput: '✗ Docker draait niet', exitCode: 1)]);
        $notifier = Mockery::mock(DesktopNotifier::class);
        $notifier->shouldReceive('notice')->once()->withArgs(fn (string $title, string $message): bool => str_contains($message, 'klant') && str_contains($message, 'Docker draait niet'));

        (new OpenLocalSite($site->id, 'local-admin'))->handle(
            app(WpOpen::class), app(SiteLinks::class), app(DesktopLauncher::class), app(SiteRegistry::class), $notifier,
        );
    });
});
