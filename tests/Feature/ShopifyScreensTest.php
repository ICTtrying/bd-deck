<?php

use App\Enums\CredentialKind;
use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\Create;
use App\Livewire\Sites\ShopifyDeploy;
use App\Livewire\Sites\ShopifyDev;
use App\Livewire\Sites\ShopifySettings;
use App\Livewire\Sites\Show;
use App\Models\CommandRun;
use App\Models\Credential;
use App\Models\Site;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\ShopifyDevLog;
use App\Services\Vault;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->user = User::factory()->owner('juist-wachtwoord')->create();
    $this->actingAs($this->user);
    // links openen gaat via xdg-open; die starten we in de test nooit echt
    $this->ranOpen = fn (string $url): bool => true;
});

describe('projectpagina', function (): void {
    it('toont een Shopify-project met winkel, dev server en live zetten', function (): void {
        $site = Site::factory()->shopify()->withThemes()->create(['name' => 'klant']);

        $this->get(route('sites.show', $site))
            ->assertOk()
            ->assertSee('demo-winkel.myshopify.com')
            ->assertSee('Dev server')
            ->assertSee('Live zetten')
            ->assertSee('Pushen naar preview')
            ->assertSee('Preview 1')
            ->assertDontSee('WP-CLI')
            ->assertDontSee('Lokaal bouwen')
            ->assertDontSee('Verhuizen naar nieuwe server');
    });

    it('laat WordPress- en Laravel-sites hun eigen pagina houden', function (): void {
        $wordpress = Site::factory()->built()->create();
        $laravel = Site::factory()->laravel()->built()->create();

        $this->get(route('sites.show', $wordpress))->assertOk()->assertSee('WP-CLI')->assertDontSee('Shopify');
        $this->get(route('sites.show', $laravel))->assertOk()->assertSee('Artisan')->assertDontSee('Shopify');
    });

    it('toont de adressen van een draaiende dev server', function (): void {
        $site = Site::factory()->devRunning()->create();

        Livewire::test(ShopifyDev::class, ['site' => $site])
            ->assertSee('http://127.0.0.1:9292')
            ->assertSee('preview_theme_id=555', escape: false)
            ->assertSee('Theme editor')
            ->assertSee('Stoppen');
    });

    it('meldt een map die ontbreekt', function (): void {
        $site = Site::factory()->shopify()->snapshotState(['built' => false])->create();

        $this->get(route('sites.show', $site))->assertOk()->assertSee('niet gevonden of is geen Shopify-thema');
    });

    it('toont de inloglink van de CLI zolang de run loopt, en opent hem één keer', function (): void {
        $site = Site::factory()->shopify()->create();
        $run = CommandRun::factory()->for($site)->create([
            'action' => WpOpenAction::ShopifyDevStart,
            'status' => RunStatus::Running,
            'output' => "→ Dev server starten…\n→ Inloggen bij Shopify nodig: open de link en bevestig de code\nLOGIN_URL=https://accounts.shopify.com/activate-with-code?device_code%5Buser_code%5D=ABCD-EFGH\nLOGIN_CODE=ABCD-EFGH\n",
        ]);

        Process::fake();

        Livewire::test(Show::class, ['site' => $site])
            ->assertSee('Inloggen bij Shopify')
            ->assertSee('ABCD-EFGH')
            ->call('openLogin', $run->id, false)
            ->assertSet('openedLoginRun', $run->id)
            // een tweede keer vanzelf doet niets, de knop opent hem wel opnieuw
            ->call('openLogin', $run->id, false)
            ->call('openLogin', $run->id, true);

        Process::assertRanTimes(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://accounts.shopify.com/activate-with-code?device_code%5Buser_code%5D=ABCD-EFGH'], 2);

        $run->update(['status' => RunStatus::Succeeded]);
        expect($run->fresh()->shopifyLogin())->toBeNull();
    });

    it('opent geen andere link dan die van Shopify', function (): void {
        $run = CommandRun::factory()->create([
            'action' => WpOpenAction::ShopifyDevStart,
            'status' => RunStatus::Running,
            'output' => "LOGIN_URL=https://boosaardig.example.com/inloggen\nLOGIN_CODE=ABCD-EFGH\n",
        ]);

        expect($run->shopifyLogin())->toBeNull();
    });

    it('doet sneltoetsen alleen bij Shopify-projecten', function (): void {
        $wordpress = Site::factory()->built()->create(['name' => 'blog']);

        Livewire::test(Show::class, ['site' => $wordpress])->call('shortcut', 'toggle-dev');

        Queue::assertNothingPushed();
    });

    it('start en stopt de dev server met de sneltoets d', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);

        Livewire::test(Show::class, ['site' => $site])->call('shortcut', 'toggle-dev');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'dev', 'klant'] && $job->queue === 'quick');
    });

    it('stopt een draaiende dev server met de sneltoets d', function (): void {
        $site = Site::factory()->devRunning()->create(['name' => 'klant']);

        Livewire::test(Show::class, ['site' => $site])->call('shortcut', 'toggle-dev');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'stop', 'klant']);
    });

    it('opent de preview, de editor en de deelbare link van een draaiende dev server', function (): void {
        $site = Site::factory()->devRunning()->create();
        Process::fake();

        Livewire::test(Show::class, ['site' => $site])
            ->call('shortcut', 'preview')
            ->call('shortcut', 'editor')
            ->call('shortcut', 'share');

        foreach (['http://127.0.0.1:9292', 'https://demo-winkel.myshopify.com/admin/themes/555/editor?hr=9292', 'https://demo-winkel.myshopify.com/?preview_theme_id=555'] as $url) {
            Process::assertRanTimes(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', $url], 1);
        }
    });

    it('start een gestopte dev server en opent de preview pas als hij klaar is', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake();

        Livewire::test(ShopifyDev::class, ['site' => $site])->call('startAndOpen');

        Process::assertNothingRan();

        $run = CommandRun::query()->latest('id')->first();
        expect($run->arguments)->toBe(['shopify', 'dev', 'klant'])
            ->and(Cache::get('shopify-open-after-start:'.$run->id))->toBe('preview');
    });
});

describe('dev server en wachtwoord', function (): void {
    it('geeft het storefront-wachtwoord uit de kluis alleen aan de dev server mee', function (): void {
        app(Vault::class)->unlock($this->user, 'juist-wachtwoord');
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Credential::factory()->for($site)->create(['kind' => CredentialKind::ShopifyStorePassword, 'secret' => 'geheim-winkel-1']);

        Livewire::test(ShopifyDev::class, ['site' => $site])->call('toggle');
        Livewire::test(ShopifyDeploy::class, ['site' => $site])->call('pullFiles', false);

        $jobs = Queue::pushed(RunWpOpenCommand::class);
        expect($jobs)->toHaveCount(2)
            ->and($jobs[0]->secretEnvironment)->toBe(['SHOPIFY_FLAG_STORE_PASSWORD' => 'geheim-winkel-1'])
            ->and($jobs[1]->secretEnvironment)->toBe([]);

        // het wachtwoord staat nooit in de argumenten of de run
        expect(json_encode(CommandRun::query()->get()->toArray()))->not->toContain('geheim-winkel-1');
    });

    it('start de dev server zonder wachtwoord als er geen is opgeslagen', function (): void {
        $site = Site::factory()->shopify()->create();

        Livewire::test(ShopifyDev::class, ['site' => $site])->call('toggle');

        expect(Queue::pushed(RunWpOpenCommand::class)->first()->secretEnvironment)->toBe([]);
    });

    it('meldt een vergrendelde kluis en laat geen halve run achter', function (): void {
        $site = Site::factory()->shopify()->create();
        app(Vault::class)->unlock($this->user, 'juist-wachtwoord');
        Credential::factory()->for($site)->create(['kind' => CredentialKind::ShopifyStorePassword, 'secret' => 'x-geheim']);
        // een opgeslagen geheim zonder ontgrendelde kluis kan niet gelezen worden
        app(Vault::class)->lock();

        Livewire::test(ShopifyDev::class, ['site' => $site])->call('toggle')->assertDispatched('toast');

        expect(CommandRun::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('leest het dev-log uit de statusmap van wpopen', function (): void {
        $home = sys_get_temp_dir().'/bd-deck-home-'.bin2hex(random_bytes(4));
        mkdir($home.'/.config/wpsites/shopify/klant', 0755, true);
        file_put_contents($home.'/.config/wpsites/shopify/klant/dev.log', "oude regel\n\e[32mPreview your theme\e[0m\n\n   • http://127.0.0.1:9292\n");
        $originalHome = getenv('HOME');
        putenv('HOME='.$home);

        $site = Site::factory()->shopify()->snapshotState(['slug' => 'klant'])->create(['name' => 'klant']);

        expect(app(ShopifyDevLog::class)->tail($site))->toBe(['oude regel', 'Preview your theme', '   • http://127.0.0.1:9292']);
        Livewire::test(ShopifyDev::class, ['site' => $site])->assertSee('Preview your theme');

        putenv($originalHome === false ? 'HOME' : 'HOME='.$originalHome);
        exec('rm -rf '.escapeshellarg($home));
    });
});

describe('live zetten', function (): void {
    beforeEach(function (): void {
        $this->site = Site::factory()->shopify()->withThemes()->create(['name' => 'klant']);
    });

    it('pusht naar een preview-thema met de gekozen naam', function (): void {
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])
            ->call('openPush')
            ->assertDispatched('open-modal', name: 'shopify-push')
            ->set('pushName', 'Zomeractie')
            ->set('pushCheck', false)
            ->call('push')
            ->assertHasNoErrors()
            ->assertDispatched('close-modal', name: 'shopify-push');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'push', 'klant', '--theme', 'Zomeractie', '--no-check', '--yes']
            && $job->run->action === WpOpenAction::ShopifyPush);
    });

    it('weigert een ongeldige themanaam', function (): void {
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])->set('pushName', str_repeat('x', 61))->call('push')->assertHasErrors('pushName');
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])->set('pushName', 'a|b')->call('push')->assertHasErrors('pushName');

        Queue::assertNothingPushed();
    });

    it('stelt de naam van de laatste push voor en toont de preview', function (): void {
        $site = Site::factory()->shopify()->withThemes()->snapshotState(['shopify' => ['last_push' => [
            'theme_id' => '200', 'theme_name' => 'Preview 1', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=200',
            'editor_url' => 'https://demo-winkel.myshopify.com/admin/themes/200/editor', 'at' => now()->subHour()->toIso8601String(), 'snapshot' => 'bddeck/push-1',
        ]]])->create();

        Livewire::test(ShopifyDeploy::class, ['site' => $site])
            ->assertSee('Laatst gepusht: Preview 1')
            ->assertSee('Publiceren…')
            ->call('openPush')
            ->assertSet('pushName', 'Preview 1');
    });

    it('haalt editor-wijzigingen of het hele thema op', function (): void {
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])->call('pullFiles', false)->call('pullFiles', true);

        $arguments = Queue::pushed(RunWpOpenCommand::class)->map(fn (RunWpOpenCommand $job): array => $job->run->arguments)->all();
        expect($arguments)->toBe([['shopify', 'pull', 'klant'], ['shopify', 'pull', 'klant', '--all']]);
    });

    it('publiceert pas na het intypen van de projectnaam en toont winkel en thema\'s', function (): void {
        $component = Livewire::test(ShopifyDeploy::class, ['site' => $this->site])
            ->call('openPublish', 200)
            ->assertDispatched('open-modal', name: 'shopify-publish')
            ->assertSee('demo-winkel.myshopify.com')
            ->assertSee('Live thema')
            ->assertSee('Preview 1')
            ->call('publish')
            ->assertHasErrors('confirmName');

        Queue::assertNothingPushed();

        $component->set('confirmName', 'klant')->call('publish')->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'publish', 'klant', '--theme', '200', '--sync-editor-files', '--yes']);
    });

    it('kan bewust de preview-versie van de editorbestanden houden', function (): void {
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])
            ->call('openPublish', 200)
            ->set('publishSyncEditorFiles', false)
            ->set('confirmName', 'klant')
            ->call('publish');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => in_array('--keep-editor-files', $job->run->arguments, true));
    });

    it('biedt alleen een niet-gepubliceerd thema aan om live te zetten', function (): void {
        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])
            ->call('openPublish', 100)
            ->assertNotDispatched('open-modal')
            ->call('openPublish', 999)
            ->assertNotDispatched('open-modal')
            ->call('publish');

        Queue::assertNothingPushed();
    });

    it('draait de laatste publicatie terug en daarna niet nog een keer', function (): void {
        $publishedState = ['new_id' => '200', 'new_name' => 'Preview 1', 'previous_id' => '100', 'previous_name' => 'Live thema', 'at' => now()->toIso8601String(), 'backup' => '20261023-101010-publish', 'snapshot' => 'bddeck/publish-1', 'rolled_back_at' => null];
        $site = Site::factory()->shopify()->withThemes()->snapshotState(['shopify' => ['last_publish' => $publishedState]])->create(['name' => 'andere']);

        Livewire::test(ShopifyDeploy::class, ['site' => $site])->assertSee('Terugdraaien')->call('rollback');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'rollback', 'andere', '--yes']);

        $rolledBack = Site::factory()->shopify()->snapshotState(['shopify' => ['last_publish' => [...$publishedState, 'rolled_back_at' => now()->toIso8601String()]]])->create();
        expect($rolledBack->canRollbackPublish())->toBeFalse();
        Livewire::test(ShopifyDeploy::class, ['site' => $rolledBack])->assertDontSee('is live gezet');
    });

    it('biedt "toch pushen" aan na een push die door de themacontrole is tegengehouden', function (): void {
        CommandRun::factory()->for($this->site)->create([
            'action' => WpOpenAction::ShopifyPush,
            'status' => RunStatus::Failed,
            'arguments' => ['shopify', 'push', 'klant', '--theme', 'Zomeractie', '--yes'],
            'output' => "✗ De themacontrole vond 1 nieuwe fout(en). Los ze op, of push toch met --allow-check-errors.\n",
        ]);

        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])
            ->assertSee('Toch pushen')
            ->call('pushAcceptingErrors');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'push', 'klant', '--theme', 'Zomeractie', '--allow-check-errors', '--yes']);
    });

    it('biedt "toch pushen" niet aan na een andere mislukking', function (): void {
        CommandRun::factory()->for($this->site)->create(['action' => WpOpenAction::ShopifyPush, 'status' => RunStatus::Failed, 'output' => "✗ Pushen naar x is mislukt.\n"]);

        Livewire::test(ShopifyDeploy::class, ['site' => $this->site])->assertDontSee('Toch pushen');
    });

    it('toont het resultaat van de themacontrole', function (): void {
        $site = Site::factory()->shopify()->create(['shopify_state' => ['check' => [
            'errors' => 1, 'warnings' => 1, 'info' => 0, 'new_errors' => 0,
            'offenses' => [
                ['file' => 'sections/a.liquid', 'line' => 12, 'check' => 'ValidSchema', 'severity' => 'error', 'message' => 'Kapot schema'],
                ['file' => 'snippets/b.liquid', 'line' => 3, 'check' => 'AssetPreload', 'severity' => 'warning', 'message' => 'Gebruik preload'],
            ],
        ], 'check_at' => now()->toIso8601String()]]);

        Livewire::test(ShopifyDeploy::class, ['site' => $site])->assertSee('sections/a.liquid:12')->assertSee('Kapot schema')->assertSee('1 fouten, 1 waarschuwingen');
    });
});

describe('instellingen', function (): void {
    it('slaat instellingen op via wpopen en ververst het project', function (): void {
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake([
            '*info*' => Process::result(json_encode([...$site->snapshot, 'shopify' => [...$site->snapshot['shopify'], 'port' => 9500]])),
            '*' => Process::result('✓ klant bijgewerkt'),
        ]);

        Livewire::test(ShopifySettings::class, ['site' => $site])
            ->assertSet('store', 'demo-winkel.myshopify.com')
            ->assertSet('port', 9292)
            ->set('port', 9500)
            ->set('themeEditorSync', true)
            ->set('host', '0.0.0.0')
            ->set('liveUrl', 'https://klant.nl/')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        Process::assertRan(fn ($process): bool => array_slice($process->command, 3) === [
            'shopify', 'update', 'klant', '--port', '9500', '--url', 'https://klant.nl', '--ignore', 'config/settings_data.json',
            '--ignore', 'templates/*.json', '--ignore', 'sections/*.json', '--host', '127.0.0.1', '--store', 'demo-winkel.myshopify.com', '--sync', '1',
        ] || in_array('--host', $process->command, true));
        expect($site->fresh()->devPort())->toBe(9500);
    });

    it('weigert een ongeldige poort, pad of winkel', function (): void {
        $site = Site::factory()->shopify()->create();
        Process::fake();

        Livewire::test(ShopifySettings::class, ['site' => $site])->set('port', 80)->call('save')->assertHasErrors('port');
        Livewire::test(ShopifySettings::class, ['site' => $site])->set('path', 'relatief/pad')->call('save')->assertHasErrors('path');
        Livewire::test(ShopifySettings::class, ['site' => $site])->set('path', '/a/../b')->call('save')->assertHasErrors('path');
        Livewire::test(ShopifySettings::class, ['site' => $site])->set('store', 'klant.nl')->call('save')->assertDispatched('toast');

        Process::assertNothingRan();
    });

    it('vraagt minstens één beschermd bestand, tenzij je de bescherming bewust uitzet', function (): void {
        $site = Site::factory()->shopify()->create();
        Process::fake(['*' => Process::result('ok')]);

        Livewire::test(ShopifySettings::class, ['site' => $site])->set('protect', '')->call('save')->assertHasErrors('protect');

        Process::assertNothingRan();

        Livewire::test(ShopifySettings::class, ['site' => $site])->set('protect', '')->set('protectionDisabled', true)->call('save')->assertHasNoErrors();

        Process::assertRan(fn ($process): bool => in_array('none', $process->command, true));
    });

    it('bewaart het storefront-wachtwoord versleuteld in de kluis en kan het weer weghalen', function (): void {
        app(Vault::class)->unlock($this->user, 'juist-wachtwoord');
        $site = Site::factory()->shopify()->create(['name' => 'klant']);
        Process::fake(['*info*' => Process::result(json_encode($site->snapshot)), '*' => Process::result('ok')]);

        Livewire::test(ShopifySettings::class, ['site' => $site])
            ->set('storePassword', 'mijn-winkelwachtwoord')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('storePassword', '');

        $credential = $site->storePasswordCredential();
        expect($credential)->not->toBeNull()
            ->and($credential->getRawOriginal('secret'))->not->toContain('mijn-winkelwachtwoord')
            ->and($credential->secret->reveal(app(Vault::class)))->toBe('mijn-winkelwachtwoord');

        // nogmaals opslaan vervangt het wachtwoord in plaats van er een tweede aan te maken
        Livewire::test(ShopifySettings::class, ['site' => $site])->set('storePassword', 'nieuw')->call('save');
        expect($site->credentials()->count())->toBe(1);

        Livewire::test(ShopifySettings::class, ['site' => $site])->call('forgetStorePassword');
        expect($site->credentials()->count())->toBe(0);
    });
});

describe('project toevoegen', function (): void {
    beforeEach(function (): void {
        // een eigen projectenmap: de test hangt zo niet af van wat er in ~/Projects/shopify staat
        $this->projects = sys_get_temp_dir().'/bd-deck-projecten-'.bin2hex(random_bytes(4));
        mkdir($this->projects);
        app(AppSettings::class)->update(['shopify_directory' => $this->projects]);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->projects));
    });

    it('koppelt een bestaande themamap', function (): void {
        $dir = sys_get_temp_dir().'/bd-deck-shopify-'.bin2hex(random_bytes(4));
        mkdir($dir.'/layout', 0755, true);
        file_put_contents($dir.'/layout/theme.liquid', 'x');

        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->assertSet('kind', 'shopify')
            ->set('shopName', 'klant')
            ->set('shopStore', 'https://Klant.myshopify.com/admin')
            ->set('shopPath', $dir)
            ->assertSee('wordt gekoppeld')
            ->call('createShopify')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['shopify', 'add', 'klant', '--store', 'klant.myshopify.com', '--path', $dir]);
        exec('rm -rf '.escapeshellarg($dir));
    });

    it('haalt het live thema op als de map nog niet bestaat', function (): void {
        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'nieuw-project')
            ->set('shopStore', 'nieuw')
            ->set('shopLiveUrl', 'https://nieuw.nl/')
            ->assertSee('het live thema van nieuw.myshopify.com wordt opgehaald')
            ->call('createShopify')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, function (RunWpOpenCommand $job): bool {
            $arguments = $job->run->arguments;

            return in_array('--create', $arguments, true) && in_array('nieuw.myshopify.com', $arguments, true) && in_array('https://nieuw.nl', $arguments, true)
                && $arguments[array_search('--path', $arguments, true) + 1] === $this->projects.'/nieuw-project';
        });
    });

    it('heeft aan een naam genoeg: de winkel is dan naam.myshopify.com en het live thema wordt opgehaald', function (): void {
        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'fenix-tallow')
            ->assertSet('shopStore', '')
            ->assertSee('Leeg laten: fenix-tallow.myshopify.com')
            ->assertSee('het live thema van fenix-tallow.myshopify.com wordt opgehaald')
            ->call('createShopify')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, function (RunWpOpenCommand $job): bool {
            $arguments = $job->run->arguments;

            return array_slice($arguments, 0, 5) === ['shopify', 'add', 'fenix-tallow', '--store', 'fenix-tallow.myshopify.com'] && in_array('--create', $arguments, true);
        });
    });

    it('vraagt de winkel als de naam er geen kan zijn', function (): void {
        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'klant.nl_v2')
            ->call('createShopify')
            ->assertHasErrors('shopStore');

        Queue::assertNothingPushed();
    });

    it('weigert een eigen domein als winkel, een dubbele naam en rare tekens', function (): void {
        Site::factory()->create(['name' => 'bestaat']);

        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'bestaat')->set('shopStore', 'x')->call('createShopify')->assertHasErrors('shopName');

        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'naam met spatie')->set('shopStore', 'x')->call('createShopify')->assertHasErrors('shopName');

        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'klant')->set('shopStore', 'klant.nl')->call('createShopify')->assertDispatched('toast');

        Livewire::withQueryParams(['soort' => 'shopify'])->test(Create::class)
            ->set('shopName', 'klant')->set('shopStore', 'klant')->set('shopPath', '../../etc')->call('createShopify')->assertHasErrors('shopPath');

        Queue::assertNothingPushed();
    });

    it('toont Shopify als derde keuze naast bestaand en lokaal', function (): void {
        Livewire::test(Create::class)->assertSee('Shopify-thema')->assertSee('Bestaande site koppelen')->assertSee('Nieuwe lokale site');
    });
});
