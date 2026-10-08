<?php

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\WpOpenAction;
use App\Models\Site;
use App\Services\WpOpen\WpOpenCommand;

it('bouwt een push met bericht, database en uploads', function (): void {
    $site = Site::factory()->create(['name' => 'klant']);

    $command = WpOpenCommand::push($site, message: '  Telefoon aangepast ', database: true, uploads: true);

    expect($command->action)->toBe(WpOpenAction::Push)
        ->and($command->arguments)->toBe(['push', 'klant', '--yes', '-m', 'Telefoon aangepast', '--db', '--uploads']);
});

it('laat de cache na een push standaard legen', function (): void {
    $site = Site::factory()->create(['name' => 'klant']);

    expect(WpOpenCommand::push($site)->arguments)->toBe(['push', 'klant', '--yes'])
        ->and(WpOpenCommand::push($site, flushCache: false)->arguments)->toContain('--no-flush');
});

it('weigert een database-push bij een site met alleen SFTP', function (): void {
    WpOpenCommand::push(Site::factory()->sftp()->create(), database: true);
})->throws(InvalidArgumentException::class, 'Database pushen kan alleen bij SSH-sites.');

it('vraagt bij een proefrun nooit om --yes', function (): void {
    $command = WpOpenCommand::pushPreview(Site::factory()->create(['name' => 'klant']));

    expect($command->arguments)->toBe(['push', 'klant', '-n'])
        ->and($command->action->locksSite())->toBeFalse();
});

it('scheidt WP-CLI-argumenten van wpopen-opties', function (): void {
    $site = Site::factory()->create(['name' => 'klant']);

    expect(WpOpenCommand::wpCli($site, ['wp', 'plugin', 'list'], live: true)->arguments)
        ->toBe(['wp', 'klant', '--live', '--', 'plugin', 'list']);
});

it('staat WP-CLI op live niet toe zonder SSH', function (): void {
    WpOpenCommand::wpCli(Site::factory()->sftp()->create(), ['plugin', 'list'], live: true);
})->throws(InvalidArgumentException::class);

it('zet alleen bekende onderdelen terug', function (): void {
    $site = Site::factory()->create(['name' => 'klant']);

    expect(WpOpenCommand::restore($site, '20261008-120000-push', ['live-files', 'rm -rf'])->arguments)
        ->toBe(['restore', 'klant', '20261008-120000-push', '--yes', '--live-files']);
});

it('weigert een back-up-id met padtekens', function (): void {
    WpOpenCommand::restore(Site::factory()->create(), '../../etc', ['live-files']);
})->throws(InvalidArgumentException::class);

it('splitst SSH-opties zoals op de commandoregel', function (): void {
    expect(WpOpenCommand::splitOptions('-p 65002'))->toBe(['-p', '65002'])
        ->and(WpOpenCommand::splitOptions(''))->toBe([]);
});

it('weigert SSH-opties met shell-tekens', function (string $options): void {
    WpOpenCommand::splitOptions($options);
})->with(['-p 22; rm -rf ~', '-o ProxyCommand=$(id)', 'geen-optie', '-p 22 | cat'])
    ->throws(InvalidArgumentException::class);

it('bouwt een handmatige site met pad, modus, provider en URL', function (): void {
    $command = WpOpenCommand::addSite('klant', 'u@host.nl', '-p 65002', 'domains/klant.nl/public_html/wp-content', SiteMode::Sftp, SiteProvider::Hostinger, 'https://klant.nl');

    expect($command->arguments)->toBe([
        'add', 'klant', '-p', '65002', 'u@host.nl',
        '--remote', 'domains/klant.nl/public_html/wp-content', '--mode', 'sftp',
        '--provider', 'hostinger', '--url', 'https://klant.nl',
    ]);
});

it('plant lange en korte acties op aparte wachtrijen', function (): void {
    expect(WpOpenAction::Rebuild->queue())->toBe('default')
        ->and(WpOpenAction::Push->queue())->toBe('default')
        ->and(WpOpenAction::Test->queue())->toBe('quick')
        ->and(WpOpenAction::Updates->queue())->toBe('quick');
});
