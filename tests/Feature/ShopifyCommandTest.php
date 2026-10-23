<?php

use App\Enums\WpOpenAction;
use App\Models\Site;
use App\Services\WpOpen\WpOpenCommand;

it('bouwt het toevoegen van een Shopify-project als argumentenlijst', function (): void {
    $command = WpOpenCommand::shopifyAdd('klant', 'https://Klant.myshopify.com/admin', path: '/home/wesley/Projects/shopify/klant', create: true, port: 9300, liveUrl: 'https://klant.nl', ignore: ['config/settings_data.json'], themeEditorSync: true, host: '0.0.0.0');

    expect($command->action)->toBe(WpOpenAction::ShopifyAdd)
        ->and($command->site)->toBeNull()
        ->and($command->arguments)->toBe([
            'shopify', 'add', 'klant', '--store', 'klant.myshopify.com',
            '--path', '/home/wesley/Projects/shopify/klant', '--port', '9300', '--url', 'https://klant.nl',
            '--ignore', 'config/settings_data.json', '--host', '0.0.0.0', '--create', '--sync',
        ]);
});

it('normaliseert de winkelnaam en weigert een eigen domein', function (string $input, ?string $expected): void {
    if ($expected === null) {
        expect(fn () => WpOpenCommand::normalizeStore($input))->toThrow(InvalidArgumentException::class, '.myshopify.com-naam');

        return;
    }

    expect(WpOpenCommand::normalizeStore($input))->toBe($expected);
})->with([
    'voorvoegsel' => ['winkel', 'winkel.myshopify.com'],
    'volledig' => ['Winkel.MyShopify.com', 'winkel.myshopify.com'],
    'adres met pad' => ['https://winkel.myshopify.com/admin/themes', 'winkel.myshopify.com'],
    'eigen domein' => ['klant.nl', null],
    'ander domein' => ['https://winkel.example.com', null],
    'rommel' => ['winkel; rm -rf', null],
]);

it('weigert ongeldige invoer bij het toevoegen', function (array $arguments, string $message): void {
    expect(fn () => WpOpenCommand::shopifyAdd(...[...['name' => 'klant', 'store' => 'klant'], ...$arguments]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'naam' => [['name' => 'naam met spatie'], 'letters, cijfers'],
    'relatief pad' => [['path' => 'projecten/klant'], 'volledig pad'],
    'pad omhoog' => [['path' => '/home/../etc'], 'volledig pad'],
    'poort te laag' => [['port' => 80], '1024 en 65535'],
    'adres' => [['liveUrl' => 'klant.nl'], 'volledig adres'],
    'patroon' => [['ignore' => ['../geheim']], 'Ongeldig patroon'],
    'host' => [['host' => '192.168.1.5'], '127.0.0.1'],
]);

it('zet een lege beschermlijst om in bewust uitzetten', function (): void {
    expect(WpOpenCommand::shopifyAdd('klant', 'klant', ignore: [])->arguments)->toContain('--ignore', 'none')
        ->and(WpOpenCommand::shopifyAdd('klant', 'klant')->arguments)->not->toContain('--ignore');
});

it('bouwt een update met alleen wat is opgegeven', function (): void {
    $site = Site::factory()->shopify()->create(['name' => 'klant']);

    expect(WpOpenCommand::shopifyUpdate($site, port: 9500, themeEditorSync: false, liveUrl: ''))
        ->toBe(['shopify', 'update', 'klant', '--port', '9500', '--url', '', '--sync', '0'])
        ->and(WpOpenCommand::shopifyUpdate($site, ignore: [], store: 'nieuw'))
        ->toBe(['shopify', 'update', 'klant', '--ignore', 'none', '--store', 'nieuw.myshopify.com']);

    expect(fn () => WpOpenCommand::shopifyUpdate(Site::factory()->create()))->toThrow(InvalidArgumentException::class, 'geen Shopify-project');
});

it('maakt van starten en stoppen de dev server bij een Shopify-project', function (): void {
    $shopify = Site::factory()->shopify()->create(['name' => 'klant']);
    $wordpress = Site::factory()->built()->create(['name' => 'blog']);

    expect(WpOpenCommand::start($shopify)->arguments)->toBe(['shopify', 'dev', 'klant'])
        ->and(WpOpenCommand::start($shopify)->action)->toBe(WpOpenAction::ShopifyDevStart)
        ->and(WpOpenCommand::stop($shopify)->arguments)->toBe(['shopify', 'stop', 'klant'])
        ->and(WpOpenCommand::stop($shopify)->action)->toBe(WpOpenAction::ShopifyDevStop)
        ->and(WpOpenCommand::start($wordpress)->arguments)->toBe(['start', 'blog'])
        ->and(WpOpenCommand::stop($wordpress)->action)->toBe(WpOpenAction::Stop);
});

it('bouwt de opdrachten voor thema\'s, ophalen en controleren', function (): void {
    $site = Site::factory()->shopify()->create(['name' => 'klant']);

    expect(WpOpenCommand::shopifyThemes($site)->arguments)->toBe(['shopify', 'themes', 'klant', '--json'])
        ->and(WpOpenCommand::shopifyPull($site)->arguments)->toBe(['shopify', 'pull', 'klant'])
        ->and(WpOpenCommand::shopifyPull($site, all: true)->arguments)->toBe(['shopify', 'pull', 'klant', '--all'])
        ->and(WpOpenCommand::shopifyCheck($site)->arguments)->toBe(['shopify', 'check', 'klant', '--json']);
});

it('bouwt een push naar een preview-thema, nooit naar live', function (): void {
    $site = Site::factory()->shopify()->create(['name' => 'klant']);

    $push = WpOpenCommand::shopifyPush($site, ' Zomeractie ', pullFirst: false, check: false, allowCheckErrors: true);

    expect($push->arguments)->toBe(['shopify', 'push', 'klant', '--theme', 'Zomeractie', '--no-pull', '--no-check', '--allow-check-errors', '--yes'])
        ->and(WpOpenCommand::shopifyPush($site)->arguments)->toBe(['shopify', 'push', 'klant', '--yes'])
        ->and(WpOpenCommand::shopifyPush($site, 'Naam')->arguments)->not->toContain('--live', '--allow-live');

    expect(fn () => WpOpenCommand::shopifyPush($site, 'a|b'))->toThrow(InvalidArgumentException::class, 'maximaal 60')
        ->and(fn () => WpOpenCommand::shopifyPush($site, str_repeat('x', 61)))->toThrow(InvalidArgumentException::class);
});

it('bouwt publiceren met een thema-id en een keuze voor de editorbestanden', function (): void {
    $site = Site::factory()->shopify()->create(['name' => 'klant']);

    expect(WpOpenCommand::shopifyPublish($site, 201)->arguments)->toBe(['shopify', 'publish', 'klant', '--theme', '201', '--sync-editor-files', '--yes'])
        ->and(WpOpenCommand::shopifyPublish($site, 201, syncEditorFiles: false)->arguments)->toBe(['shopify', 'publish', 'klant', '--theme', '201', '--keep-editor-files', '--yes'])
        ->and(WpOpenCommand::shopifyRollback($site)->arguments)->toBe(['shopify', 'rollback', 'klant', '--yes']);

    expect(fn () => WpOpenCommand::shopifyPublish($site, 0))->toThrow(InvalidArgumentException::class);
});

it('weigert Shopify-opdrachten voor een andere soort site', function (): void {
    $wordpress = Site::factory()->built()->create();

    expect(fn () => WpOpenCommand::shopifyPush($wordpress))->toThrow(InvalidArgumentException::class, 'geen Shopify-project')
        ->and(fn () => WpOpenCommand::shopifyPublish($wordpress, 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WpOpenCommand::shopifyThemes($wordpress))->toThrow(InvalidArgumentException::class);
});

it('gooit de themamap van een Shopify-project nooit weg, ook niet met --purge', function (): void {
    $shopify = Site::factory()->shopify()->create(['name' => 'klant']);
    $local = Site::factory()->localOnly()->create(['name' => 'lokaal']);

    expect(WpOpenCommand::removeSite($shopify, purgeLocal: true)->arguments)->toBe(['remove', 'klant', '--yes'])
        ->and(WpOpenCommand::removeSite($local, purgeLocal: true)->arguments)->toBe(['remove', 'lokaal', '--yes', '--purge']);
});

it('laat Shopify-acties buiten de zware wachtrij en de site-vergrendeling waar dat past', function (): void {
    expect(WpOpenAction::ShopifyDevStart->queue())->toBe('quick')
        ->and(WpOpenAction::ShopifyThemes->locksSite())->toBeFalse()
        ->and(WpOpenAction::ShopifyCheck->locksSite())->toBeFalse()
        ->and(WpOpenAction::ShopifyPush->locksSite())->toBeTrue()
        ->and(WpOpenAction::ShopifyPublish->locksSite())->toBeTrue()
        ->and(WpOpenAction::ShopifyPull->queue())->toBe('default')
        ->and(WpOpenAction::ShopifyAdd->changesSiteList())->toBeTrue();
});
