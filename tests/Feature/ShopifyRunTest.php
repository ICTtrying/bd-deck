<?php

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\CommandPalette;
use App\Livewire\Dashboard;
use App\Models\CommandRun;
use App\Models\Site;
use App\Models\User;
use App\Services\DesktopNotifier;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

$themes = [
    ['id' => 100, 'name' => 'Live thema', 'role' => 'live', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=100', 'editor_url' => 'https://demo-winkel.myshopify.com/admin/themes/100/editor', 'updated_at' => null],
    ['id' => 200, 'name' => 'Preview 1', 'role' => 'unpublished', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=200', 'editor_url' => 'https://demo-winkel.myshopify.com/admin/themes/200/editor', 'updated_at' => null],
];

describe('uitvoering van Shopify-acties', function () use ($themes): void {
    it('houdt het wachtwoord uit het logboek, ook als de CLI het uitschrijft', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake([
            '*info*' => Process::result(json_encode($site->snapshot)),
            '*' => Process::describe()->output("Wachtwoord geheim-winkel-99 is gebruikt\n")->exitCode(0),
        ]);

        $run = app(CommandRunner::class)->queue(WpOpenCommand::start($site), ['SHOPIFY_FLAG_STORE_PASSWORD' => 'geheim-winkel-99'])->refresh();

        expect($run->output)->toBe("Wachtwoord •••• is gebruikt\n")
            ->and($run->output)->not->toContain('geheim-winkel-99');
    });

    it('maskeert ook een SSH-wachtwoord dat als omgeving meeging', function (): void {
        $site = Site::factory()->create();
        Process::fake(['*info*' => Process::result(json_encode($site->snapshot)), '*' => Process::describe()->output("debug: WPO_PASS=ssh-geheim-1\n")->exitCode(0)]);

        $run = app(CommandRunner::class)->queue(WpOpenCommand::rebuild($site), ['WPO_PASS' => 'ssh-geheim-1'])->refresh();

        expect($run->output)->not->toContain('ssh-geheim-1')->and($run->output)->toContain('••••');
    });

    it('bewaart de themalijst bij het project', function () use ($themes): void {
        $site = Site::factory()->shopify()->create();
        Process::fake(['*' => Process::result("→ Thema's van demo-winkel.myshopify.com ophalen…\n".json_encode($themes)."\n")]);

        app(CommandRunner::class)->queue(WpOpenCommand::shopifyThemes($site));

        expect($site->fresh()->shopifyThemes())->toBe($themes)
            ->and($site->fresh()->shopifyThemesCheckedAt())->not->toBeNull();
    });

    it('bewaart de uitkomst van de themacontrole, ook als die fouten vond', function (): void {
        $site = Site::factory()->shopify()->create();
        $result = ['errors' => 2, 'warnings' => 1, 'info' => 0, 'new_errors' => 1, 'offenses' => [['file' => 'sections/a.liquid', 'line' => 3, 'check' => 'ValidSchema', 'severity' => 'error', 'message' => 'Kapot']]];
        Process::fake(['*' => Process::result("✗ 2 fouten\n".json_encode($result)."\n", exitCode: 1)]);

        $run = app(CommandRunner::class)->queue(WpOpenCommand::shopifyCheck($site))->refresh();

        expect($run->status)->toBe(RunStatus::Failed)
            ->and($site->fresh()->shopifyCheck())->toBe($result);
    });

    it('haalt na een push, publicatie of terugdraaien stilletjes de themalijst opnieuw op', function (string $action): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        $command = match ($action) {
            'push' => WpOpenCommand::shopifyPush($site),
            'publiceren' => WpOpenCommand::shopifyPublish($site, 200),
            'terugdraaien' => WpOpenCommand::shopifyRollback($site),
        };
        Process::fake(['*info*' => Process::result(json_encode($site->snapshot)), '*' => Process::result("✓ Klaar\n")]);

        app(CommandRunner::class)->queue($command);

        $refresh = CommandRun::query()->where('action', WpOpenAction::ShopifyThemes)->first();
        expect($refresh)->not->toBeNull()->and($refresh->arguments)->toBe(['shopify', 'themes', 'klant', '--json']);
    })->with(['push', 'publiceren', 'terugdraaien']);

    it('vertelt wat een mislukte push is, zonder de themalijst te verversen', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake(['*info*' => Process::result(json_encode($site->snapshot)), '*' => Process::result(errorOutput: "✗ Geen toegang\n", exitCode: 1)]);

        $run = app(CommandRunner::class)->queue(WpOpenCommand::shopifyPush($site))->refresh();

        expect($run->status)->toBe(RunStatus::Failed)
            ->and(CommandRun::query()->where('action', WpOpenAction::ShopifyThemes)->exists())->toBeFalse();
    });

    it('opent de preview na het starten als dat gevraagd is, en anders niets', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake([
            '*info*' => Process::result(json_encode($site->snapshot)),
            '*' => Process::result("✓ Dev server van klant draait op http://127.0.0.1:9292\nPREVIEW_URL=http://127.0.0.1:9292\nEDITOR_URL=https://demo-winkel.myshopify.com/admin/themes/555/editor?hr=9292\n"),
        ]);

        Queue::fake();
        $asked = app(CommandRunner::class)->queue(WpOpenCommand::start($site));
        $plain = app(CommandRunner::class)->queue(WpOpenCommand::start($site));
        Cache::put('shopify-open-after-start:'.$asked->id, 'editor', 60);
        Queue::pushed(RunWpOpenCommand::class)->each(fn (RunWpOpenCommand $job) => $job->handle(app(WpOpen::class), app(SiteRegistry::class), app(DesktopNotifier::class)));

        Process::assertRanTimes(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://demo-winkel.myshopify.com/admin/themes/555/editor?hr=9292'], 1);
        Process::assertRanTimes(fn ($process): bool => ($process->command[2] ?? null) === 'xdg-open', 1);
        expect(Cache::has('shopify-open-after-start:'.$asked->id))->toBeFalse()
            ->and($plain->fresh()->reported('PREVIEW_URL'))->toBe('http://127.0.0.1:9292');
    });

    it('leest SLEUTEL=waarde-regels uit de uitvoer van het script', function (): void {
        $run = CommandRun::factory()->create(['output' => "→ Stap 6/6\nBACKUP=20261023-101010-publish\nPUBLISH_DRIFT=2\n"]);

        expect($run->reported('BACKUP'))->toBe('20261023-101010-publish')
            ->and($run->reported('PUBLISH_DRIFT'))->toBe('2')
            ->and($run->reported('ONBEKEND'))->toBeNull();
    });
});

describe('sitelijst en dashboard', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        $this->actingAs(User::factory()->owner()->create());
    });

    it('neemt een Shopify-project uit wpopen list over in de sitelijst', function (): void {
        $snapshot = Site::factory()->shopify()->make(['name' => 'klant'])->snapshot;
        Process::fake(['*list*' => Process::result(json_encode([$snapshot]))]);

        app(SiteRegistry::class)->sync();
        $site = Site::query()->where('name', 'klant')->first();

        expect($site->isShopify())->toBeTrue()
            ->and($site->isLocalOnly())->toBeFalse()
            ->and($site->isLaravel())->toBeFalse()
            ->and($site->shopifyStore())->toBe('demo-winkel.myshopify.com')
            ->and($site->devPort())->toBe(9292)
            ->and($site->isRunning())->toBeFalse()
            ->and($site->supportsUpdateCheck())->toBeFalse()
            ->and($site->supportsMultisite())->toBeFalse()
            ->and($site->shopifyAdminUrl('/x'))->toBe('https://admin.shopify.com/store/demo-winkel/themes/x')
            ->and($site->protectedPatterns())->toBe(['config/settings_data.json', 'templates/*.json', 'sections/*.json']);
    });

    it('toont een draaiende dev server als draaiend', function (): void {
        $site = Site::factory()->devRunning()->create();

        expect($site->isRunning())->toBeTrue()
            ->and($site->devPreviewUrl())->toBe('http://127.0.0.1:9292')
            ->and($site->devShareUrl())->toContain('preview_theme_id=555')
            ->and($site->devEditorUrl())->toContain('/themes/555/editor');
    });

    it('toont Shopify-projecten op het dashboard met eigen acties', function (): void {
        Site::factory()->shopify()->create(['name' => 'klantwinkel']);
        Site::factory()->devRunning()->create(['name' => 'draaiend']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('klantwinkel')
            ->assertSee('demo-winkel.myshopify.com')
            ->assertSee('Dev server uit')
            ->assertSee('Dev server draait')
            ->assertSee('Start &amp; preview', escape: false)
            ->assertSee('Shopify-admin openen')
            ->assertDontSee('Lokaal bouwen');
    });

    it('start de dev server vanuit het dashboard', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klantwinkel']);

        Livewire::test(Dashboard::class)->call('runQuick', $site->id, 'start');
        Livewire::test(Dashboard::class)->call('launch', $site->id, 'local-site');

        $arguments = Queue::pushed(RunWpOpenCommand::class)->map(fn (RunWpOpenCommand $job): array => $job->run->arguments)->all();
        expect($arguments)->toBe([['shopify', 'dev', 'klantwinkel'], ['shopify', 'dev', 'klantwinkel']]);
    });

    it('slaat Shopify-projecten over bij bulkacties die daar niet bij horen', function (): void {
        Process::fake(['*' => Process::result('[]')]);
        $shopify = Site::factory()->shopify()->create(['name' => 'winkel']);
        $wordpress = Site::factory()->built()->create(['name' => 'blog']);

        Livewire::test(Dashboard::class)->set('selected', [$shopify->id, $wordpress->id])->call('bulkUpdates')
            ->assertDispatched('toast', title: 'Updates controleren', message: 'Gestart voor één site · 1 overgeslagen (Shopify-project)');
        Livewire::test(Dashboard::class)->set('selected', [$shopify->id, $wordpress->id])->call('bulkCache', 'local');
        Livewire::test(Dashboard::class)->set('selected', [$shopify->id, $wordpress->id])->call('bulkBackup');

        $arguments = Queue::pushed(RunWpOpenCommand::class)->map(fn (RunWpOpenCommand $job): array => $job->run->arguments)->all();
        expect(collect($arguments)->flatten()->contains('winkel'))->toBeFalse()
            ->and($arguments)->toHaveCount(3);
    });

    it('filtert op provider Shopify', function (): void {
        Site::factory()->shopify()->create(['name' => 'winkel']);
        Site::factory()->create(['name' => 'blog']);

        Livewire::test(Dashboard::class)->set('provider', 'shopify')->assertSee('winkel')->assertDontSee('blog');
    });

    it('biedt in de snelzoeker Shopify-acties aan en geen WP-admin of SSH', function (): void {
        Site::factory()->shopify()->create(['name' => 'winkelnaam']);

        $labels = collect(Livewire::test(CommandPalette::class)->set('query', 'winkelnaam')->instance()->results)->pluck('label');

        expect($labels)->toContain('Dev server starten en preview openen', 'Theme editor openen', 'Live winkel openen', 'Shopify-admin openen')
            ->not->toContain('WP-admin lokaal')
            ->not->toContain('SSH-sessie')
            ->not->toContain('WP-admin live');
    });

    it('laat een Shopify-project niet meedoen aan de updatecontrole op de achtergrond', function (): void {
        $site = Site::factory()->shopify()->create();

        expect(Site::query()->get()->filter->supportsUpdateCheck())->toHaveCount(0)
            ->and($site->updatesCount())->toBe(0);
    });
});
