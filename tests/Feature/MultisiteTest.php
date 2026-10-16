<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\Create;
use App\Livewire\Sites\Multisite;
use App\Livewire\Sites\Show;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeServer;

describe('wpopen multisite', function (): void {
    beforeEach(function (): void {
        $this->server = FakeServer::create();
        file_put_contents($this->server->home.'/.config/wpsites/sites', "nieuw||||local||\n", FILE_APPEND);

        // lokale site zoals `wpopen new` hem achterlaat, met de wp-config die ddev aanmaakt
        $this->project = $this->server->sites.'/nieuw';
        mkdir($this->project, recursive: true);
        touch($this->project.'/.wpopen-ready');
        file_put_contents($this->project.'/wp-config.php', "<?php\n/**\n * #ddev-generated: Automatically generated WordPress settings file.\n */\ndefine( 'DB_CHARSET', 'utf8' );\n\n/* That's all, stop editing! Happy publishing. */\nrequire_once ABSPATH . '/wp-settings.php';\n");
    });

    afterEach(function (): void {
        $this->server->destroy();
    });

    it('zet een lokale site om naar multisite zonder de ddev-instellingen die WP_HOME vastzetten', function (): void {
        $result = $this->server->run(['multisite', 'nieuw']);

        $config = file_get_contents($this->project.'/wp-config.php');
        $site = json_decode($this->server->run(['info', 'nieuw', '--json'])->output(), true);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($this->server->calls())->toContain('ddev wp core multisite-convert --subdomains')
            ->and($config)->not->toContain('#ddev-generated')
            ->and($config)->toContain("defined( 'DB_USER' ) || define( 'DB_USER', 'db' );", "define( 'COOKIE_DOMAIN', false );")
            ->and(strpos($config, 'wpopen-multisite'))->toBeLessThan(strpos($config, "That's all, stop editing"))
            ->and($site)->toMatchArray(['multisite' => true, 'subsites' => []]);
    });

    it('voegt een subsite toe en zet het adres in DDEV', function (): void {
        $this->server->run(['multisite', 'nieuw'])->throw();

        $result = $this->server->run(['multisite', 'nieuw', '--add', 'Winkel'], [
            'FAKE_SITE_LIST' => 'blog_id,domain\n1,nieuw.ddev.site\n2,winkel.nieuw.ddev.site\n3,klant2.test\n',
        ]);

        $site = json_decode($this->server->run(['info', 'nieuw', '--json'])->output(), true);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($this->server->calls())->toContain(
                "wpmu_create_blog('winkel.nieuw.ddev.site', '/'",
                'ddev wp theme activate enfold --url=winkel.nieuw.ddev.site',
                'ddev wp plugin activate forminator wpmudev-updates --url=winkel.nieuw.ddev.site',
                'ddev restart',
            )
            ->and(file_get_contents($this->project.'/.ddev/config.wpopen-multisite.yaml'))->toContain("additional_fqdns:\n  - winkel.nieuw.ddev.site\n  - klant2.test\n")
            ->and($site['subsites'])->toBe([
                ['id' => 2, 'url' => 'https://winkel.nieuw.ddev.site'],
                ['id' => 3, 'url' => 'https://klant2.test'],
            ]);
    });

    it('haalt een subsite weg en ruimt de DDEV-adressen op', function (): void {
        $this->server->run(['multisite', 'nieuw'])->throw();
        $this->server->run(['multisite', 'nieuw', '--add', 'winkel'], ['FAKE_SITE_LIST' => 'blog_id,domain\n1,nieuw.ddev.site\n2,winkel.nieuw.ddev.site\n'])->throw();

        $result = $this->server->run(['multisite', 'nieuw', '--remove', '2']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($this->server->calls())->toContain('ddev wp site delete 2 --yes')
            ->and($this->project.'/.ddev/config.wpopen-multisite.yaml')->not->toBeFile();
    });

    it('weigert ongeldige invoer', function (array $arguments, string $message): void {
        $this->server->run(['multisite', 'nieuw'])->throw();

        $result = $this->server->run(['multisite', 'nieuw', ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message);
    })->with([
        'hoofdsite weghalen' => [['--remove', '1'], 'de hoofdsite kan niet weg'],
        'adres met rare tekens' => [['--add', 'win;kel'], 'Ongeldig adres'],
        'adres van de hoofdsite' => [['--add', 'nieuw.ddev.site'], 'adres van de hoofdsite'],
    ]);

    it('weigert multisite bij een site met live-server', function (): void {
        $this->server->buildLocalSite();

        $result = $this->server->run(['multisite', 'demo']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('alleen bij lokale sites');
    });

    it('zet een multisite niet live', function (): void {
        $this->server->run(['multisite', 'nieuw'])->throw();

        $result = $this->server->run(['migrate', 'nieuw', 'u@server.example', '--url', 'https://klant.nl', '--yes']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('is een multisite');
    });
});

describe('multisite-paneel', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Process::fake();
        $this->actingAs(User::factory()->owner()->create());
        Setting::query()->create(['key' => 'local_site_suffix', 'value' => 'abcd']);
    });

    it('staat alleen bij lokale WordPress-sites', function (): void {
        Livewire::test(Show::class, ['site' => Site::factory()->localOnly()->create()])->assertSeeLivewire(Multisite::class);
        Livewire::test(Show::class, ['site' => Site::factory()->built()->create()])->assertDontSeeLivewire(Multisite::class);
    });

    it('maakt een nieuwe lokale site meteen als multisite', function (): void {
        Livewire::test(Create::class)
            ->set('kind', 'lokaal')
            ->set('localName', 'proef')
            ->set('localMultisite', true)
            ->call('createLocal')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['new', 'proef-abcd', '--multisite']);
    });

    it('zet een site om naar multisite', function (): void {
        $site = Site::factory()->localOnly()->create(['name' => 'proef']);

        Livewire::test(Multisite::class, ['site' => $site])->call('convert');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['multisite', 'proef']);
    });

    it('voegt een subsite toe en controleert het adres', function (): void {
        $site = Site::factory()->multisite()->create(['name' => 'proef']);

        Livewire::test(Multisite::class, ['site' => $site])
            ->set('address', 'klant 2')
            ->call('addSubsite')
            ->assertHasErrors('address')
            ->set('address', 'Klant2.test')
            ->call('addSubsite')
            ->assertHasNoErrors()
            ->assertSet('address', '');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['multisite', 'proef', '--add', 'klant2.test']);
    });

    it('verwijdert alleen subsites uit de eigen lijst', function (): void {
        $site = Site::factory()->multisite([['id' => 2, 'url' => 'https://winkel.proef.ddev.site']])->create(['name' => 'proef']);

        Livewire::test(Multisite::class, ['site' => $site])
            ->assertSee('winkel.proef.ddev.site')
            ->call('confirmRemove', 7)
            ->assertSet('removing', null)
            ->call('confirmRemove', 2)
            ->call('removeSubsite');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['multisite', 'proef', '--remove', '2']);
    });

    it('opent een subsite in de browser', function (): void {
        $site = Site::factory()->multisite([['id' => 2, 'url' => 'https://winkel.proef.ddev.site']])->create();

        Livewire::test(Multisite::class, ['site' => $site])->call('open', 2, true);

        Process::assertRan(fn ($process): bool => $process->command === ['setsid', '-f', 'xdg-open', 'https://winkel.proef.ddev.site/wp-admin/']);
    });
});
