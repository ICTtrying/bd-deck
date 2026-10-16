<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\Create;
use App\Livewire\Sites\Migrate;
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

    describe('live zetten', function (): void {
        beforeEach(function (): void {
            // lokale git-repo zoals `wpopen new` die achterlaat, en een lege WordPress bij de host
            $wpContent = $this->project.'/wp-content';
            mkdir($wpContent.'/themes/eigen', recursive: true);
            file_put_contents($wpContent.'/themes/eigen/style.css', "/* Theme Name: Eigen */\n");
            $this->server->git($wpContent, ['init', '-q', '-b', 'main']);
            $this->server->git($wpContent, ['add', '-A']);
            $this->server->git($wpContent, ['commit', '-qm', 'Start']);
            $this->server->git($wpContent, ['checkout', '-q', '-b', 'dev']);

            $this->destination = $this->server->root.'/klant/public_html/wp-content';
            mkdir($this->destination, recursive: true);
            file_put_contents(dirname($this->destination).'/wp-config.php', "<?php \$table_prefix = 'wp_';\n");

            $this->subsites = ['FAKE_SITE_LIST' => 'blog_id,domain\n1,nieuw.ddev.site\n2,winkel.nieuw.ddev.site\n3,klant2.test\n'];
            $this->server->run(['multisite', 'nieuw'])->throw();
        });

        it('zet een multisite live met een eigen adres per subsite', function (): void {
            $result = $this->server->run([
                'migrate', 'nieuw', 'u@klant.example.test', '--url', 'https://klant.nl',
                '--remote', $this->destination, '--map', 'klant2.test=https://klant2.nl', '--yes',
            ], $this->subsites);

            $calls = $this->server->calls();

            expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
                ->and($calls)->toContain(
                    'search-replace winkel.nieuw.ddev.site winkel.klant.nl --all-tables-with-prefix',
                    'search-replace klant2.test klant2.nl --all-tables-with-prefix',
                    'search-replace nieuw.ddev.site klant.nl --all-tables-with-prefix',
                    'config set MULTISITE true --raw --type=constant',
                    'config set SUBDOMAIN_INSTALL true --raw --type=constant',
                    'config set COOKIE_DOMAIN false --raw --type=constant',
                    'config set DOMAIN_CURRENT_SITE klant.nl --type=constant',
                )
                // subsites eerst, anders wordt winkel.nieuw.ddev.site al half omgezet naar winkel.klant.nl
                ->and(strpos($calls, 'search-replace winkel.nieuw.ddev.site'))->toBeLessThan(strpos($calls, 'search-replace nieuw.ddev.site'))
                ->and($result->output())->toContain('Nog te doen bij de host', 'winkel.klant.nl', 'klant2.nl')
                ->and($this->server->sitesFile())->toContain("nieuw|u@klant.example.test||{$this->destination}|ssh|");
        });

        it('vraagt het live-adres van een subsite met een eigen domein', function (): void {
            $result = $this->server->run([
                'migrate', 'nieuw', 'u@klant.example.test', '--url', 'https://klant.nl', '--remote', $this->destination, '--yes',
            ], $this->subsites);

            expect($result->failed())->toBeTrue()
                ->and($result->errorOutput())->toContain('Geef het live-adres voor klant2.test')
                ->and($this->server->calls())->not->toContain('db import');
        });

        it('weigert alleen SFTP', function (): void {
            $result = $this->server->run([
                'migrate', 'nieuw', 'u@klant.example.test', '--url', 'https://klant.nl', '--remote', $this->destination, '--mode', 'sftp',
                '--map', 'klant2.test=klant2.nl', '--yes',
            ], $this->subsites);

            expect($result->failed())->toBeTrue()
                ->and($result->errorOutput())->toContain('alleen via SSH');
        });
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

describe('multisite live zetten in de app', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Process::fake();
        $this->actingAs(User::factory()->owner()->create());

        $this->site = Site::factory()->multisite([
            ['id' => 2, 'url' => 'https://winkel.proef.ddev.site'],
            ['id' => 3, 'url' => 'https://klant2.test'],
        ])->create(['name' => 'proef']);

        $this->fillServer = fn ($component) => $component
            ->set('form.user', 'u123')
            ->set('form.host', 'server.example')
            ->set('form.provider', 'hostinger')
            ->set('form.liveUrl', 'https://klant.nl')
            ->set('confirmText', 'proef');
    });

    it('toont de knop en een voorstel per subsite', function (): void {
        Livewire::test(Show::class, ['site' => $this->site])->assertSee(route('sites.migrate', $this->site));

        Livewire::test(Migrate::class, ['site' => $this->site])
            ->set('form.liveUrl', 'https://klant.nl')
            ->assertSee('winkel.klant.nl')
            ->assertDontSee(__('Alleen het domein omzetten'));
    });

    it('geeft ingevulde adressen door en laat wpopen de rest voorstellen', function (): void {
        ($this->fillServer)(Livewire::test(Migrate::class, ['site' => $this->site]))
            ->set('subsiteHosts.3', 'https://Klant2.nl/')
            ->call('migrate')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === [
            'migrate', 'proef', 'u123@server.example', '--url', 'https://klant.nl', '--yes', '--provider', 'hostinger', '--map', 'klant2.test=klant2.nl',
        ]);
    });

    it('vraagt het adres van een subsite met een eigen domein', function (): void {
        ($this->fillServer)(Livewire::test(Migrate::class, ['site' => $this->site]))
            ->call('migrate')
            ->assertHasErrors('subsiteHosts.3')
            ->assertHasNoErrors('subsiteHosts.2');

        Queue::assertNothingPushed();
    });

    it('weigert alleen SFTP en het adres van de hoofdsite', function (): void {
        ($this->fillServer)(Livewire::test(Migrate::class, ['site' => $this->site]))
            ->set('subsiteHosts.3', 'klant.nl')
            ->call('migrate')
            ->assertHasErrors('subsiteHosts.3')
            ->set('subsiteHosts.3', 'klant2.nl')
            ->set('form.discover', false)
            ->set('form.mode', 'sftp')
            ->set('form.remotePath', 'public_html/wp-content')
            ->call('migrate')
            ->assertHasErrors('form.mode');

        Queue::assertNothingPushed();
    });

    it('zet bij een live multisite de database niet mee', function (): void {
        $site = Site::factory()->built()->snapshotState(['multisite' => true])->create();

        Livewire::test(Show::class, ['site' => $site])->assertDontSee(__('Database opnieuw ophalen'));
    });
});
