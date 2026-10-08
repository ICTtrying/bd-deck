<?php

use App\Exceptions\WpOpenException;
use App\Services\AppSettings;
use App\Services\WpOpen\WpOpen;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('roept het ingestelde script aan in een eigen procesgroep', function (): void {
    app(AppSettings::class)->update(['script_path' => '/opt/wpopen']);

    expect(app(WpOpen::class)->command(['list', '--json']))->toBe(['setsid', 'bash', '/opt/wpopen', 'list', '--json']);
});

it('draait niet-interactief met de paden uit de instellingen', function (): void {
    app(AppSettings::class)->update(['sites_directory' => '/data/sites', 'ssh_key_path' => '/keys/werk']);

    $environment = app(WpOpen::class)->environment(['WPO_PASS' => 'x']);

    expect($environment)->toMatchArray([
        'WP_SITES_DIR' => '/data/sites',
        'WPO_SSH_KEY' => '/keys/werk',
        'WPO_NONINTERACTIVE' => '1',
        'WPO_PASS' => 'x',
    ])->and($environment['PATH'])->toStartWith(getenv('HOME').'/.local/bin:');
});

it('leest de sitelijst als JSON en negeert ruis ervoor', function (): void {
    Process::fake(['*' => Process::result("· waarschuwing van een plugin\n[{\"name\":\"klant\"}]\n")]);

    expect(app(WpOpen::class)->sites())->toBe([['name' => 'klant']]);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['setsid', 'bash', app(AppSettings::class)->scriptPath(), 'list', '--json']
        && $process->environment['WPO_NONINTERACTIVE'] === '1');
});

it('geeft de echte foutmelding van het script door', function (): void {
    Process::fake(['*' => Process::result(output: "→ Bezig…\n", errorOutput: "✗ Site 'x' onbekend. Zie: wpopen list\n", exitCode: 1)]);

    app(WpOpen::class)->site('x');
})->throws(WpOpenException::class, "Site 'x' onbekend. Zie: wpopen list");

it('vraagt een inloglink op voor live met gebruiker', function (): void {
    Process::fake(['*' => Process::result("https://klant.nl/wpo-abc.php?t=123\n")]);

    expect(app(WpOpen::class)->loginUrl('klant', live: true, user: 'wesley'))->toBe('https://klant.nl/wpo-abc.php?t=123');

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 3) === ['login', 'klant', '--live', '--user', 'wesley']);
});

it('vertrouwt geen inloglink die geen https is', function (): void {
    Process::fake(['*' => Process::result("file:///etc/passwd\n")]);

    app(WpOpen::class)->loginUrl('klant', live: false);
})->throws(WpOpenException::class);

it('stopt de hele procesgroep', function (): void {
    Process::fake();

    app(WpOpen::class)->terminate(4242);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['kill', '-TERM', '--', '-4242']);
});
