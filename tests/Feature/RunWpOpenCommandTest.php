<?php

use App\Enums\RunStatus;
use App\Jobs\RunWpOpenCommand;
use App\Models\Site;
use App\Services\DesktopNotifier;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

it('zet een actie op de juiste wachtrij met versleutelde geheimen', function (): void {
    Queue::fake();
    $site = Site::factory()->create();

    $run = app(CommandRunner::class)->queue(WpOpenCommand::rebuild($site), ['WPO_PASS' => 'geheim']);

    expect($run->status)->toBe(RunStatus::Queued)
        ->and($run->site_name)->toBe($site->name)
        ->and($run->arguments)->toBe(['rebuild', $site->name, '--yes']);

    Queue::assertPushedOn('default', RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->is($run)
        && $job->secretEnvironment === ['WPO_PASS' => 'geheim']);
});

it('schrijft de uitvoer weg en ververst de site na afloop', function (): void {
    $site = Site::factory()->built()->create(['name' => 'klant']);

    Process::fake([
        '*info*' => Process::result(json_encode([...$site->snapshot, 'git' => ['dirty' => 3]])),
        '*' => Process::describe()->output("→ Code van live ophalen…\n")->output("\e[32m✓ Klaar\e[0m\n")->exitCode(0),
    ]);

    $run = app(CommandRunner::class)->queue(WpOpenCommand::pullCode($site));
    $run->refresh();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->output)->toBe("→ Code van live ophalen…\n✓ Klaar\n")
        ->and($run->steps()->all())->toBe(['Code van live ophalen…'])
        ->and($run->finished_at)->not->toBeNull()
        ->and($site->fresh()->data('git.dirty'))->toBe(3)
        ->and($site->fresh()->last_activity_at)->not->toBeNull();

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 3) === ['pull', 'klant', '--code']);
});

it('bewaart de uitkomst van een verbindingstest bij de site', function (): void {
    $site = Site::factory()->create();
    $checks = [['key' => 'connection', 'status' => 'ok', 'label' => 'SSH bereikbaar', 'detail' => '40 ms']];
    Process::fake(['*' => Process::result(json_encode($checks))]);

    app(CommandRunner::class)->queue(WpOpenCommand::test($site));

    expect($site->fresh()->health)->toBe($checks)
        ->and($site->fresh()->healthTone())->toBe('success');
});

it('markeert een mislukte actie als mislukt', function (): void {
    $site = Site::factory()->create();
    Process::fake(['*info*' => Process::result(json_encode($site->snapshot)), '*' => Process::result(errorOutput: "✗ Inloggen mislukt\n", exitCode: 1)]);

    $run = app(CommandRunner::class)->queue(WpOpenCommand::build($site))->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exit_code)->toBe(1)
        ->and($run->output)->toContain('Inloggen mislukt');
});

it('slaat een actie over die al geannuleerd is', function (): void {
    Queue::fake();
    $site = Site::factory()->create();
    $runner = app(CommandRunner::class);
    $run = $runner->queue(WpOpenCommand::build($site));

    $runner->cancel($run);
    Process::fake();
    (new RunWpOpenCommand($run))->handle(app(WpOpen::class), app(SiteRegistry::class), app(DesktopNotifier::class));

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled);
    Process::assertNothingRan();
});
