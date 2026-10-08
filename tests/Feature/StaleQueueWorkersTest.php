<?php

use App\Services\StaleQueueWorkers;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

$worker = '/tmp/.mount_BD-Dec123/resources/build/php/php -d memory_limit=256M artisan queue:listen --name=default --queue=default';

it('herkent een achtergebleven worker van BD Deck', function () use ($worker): void {
    expect(StaleQueueWorkers::isStale($worker, 3643, '/usr/lib/systemd/systemd --user'))->toBeTrue()
        ->and(StaleQueueWorkers::isStale($worker, 1, '/sbin/init'))->toBeTrue();
});

it('laat de workers van de draaiende app staan', function () use ($worker): void {
    expect(StaleQueueWorkers::isStale($worker, 5000, '/tmp/.mount_BD-Dec123/bd-deck --type=utility'))->toBeFalse();
});

it('laat andere PHP-processen en andere apps met rust', function (): void {
    expect(StaleQueueWorkers::isStale('php artisan queue:work --tries=3', 1, '/sbin/init'))->toBeFalse()
        ->and(StaleQueueWorkers::isStale('/opt/Ander/resources/build/php/php artisan queue:listen --name=default', 1, '/sbin/init'))->toBeFalse();
});

it('stopt alleen de verweesde workers', function () use ($worker): void {
    Process::fake();
    $proc = sys_get_temp_dir().'/fake-proc-'.bin2hex(random_bytes(4));
    $process = function (int $pid, int $parent, string $command) use ($proc): void {
        File::ensureDirectoryExists("{$proc}/{$pid}");
        file_put_contents("{$proc}/{$pid}/cmdline", str_replace(' ', "\0", $command));
        file_put_contents("{$proc}/{$pid}/status", "Name:\tx\nPPid:\t{$parent}\n");
    };
    $process(3643, 1, '/usr/lib/systemd/systemd --user');
    $process(4000, 1, '/tmp/.mount_BD-Dec123/bd-deck');
    $process(4100, 3643, $worker);
    $process(4200, 4000, $worker);

    expect((new StaleQueueWorkers($proc))->reap())->toBe([4100]);

    Process::assertRan(fn (PendingProcess $p): bool => $p->command === ['kill', '-TERM', '4100']);
    File::deleteDirectory($proc);
});
