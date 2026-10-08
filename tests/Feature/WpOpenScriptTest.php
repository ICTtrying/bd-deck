<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServer;

beforeEach(function (): void {
    $this->server = FakeServer::create();
});

afterEach(function (): void {
    $this->server->destroy();
});

describe('sitelijst', function (): void {
    it('vult oude regels aan met modus en provider', function (): void {
        file_put_contents($this->server->home.'/.config/wpsites/sites', "oud|u@site-serverwpmu.tempurl.host||site/public_html/wp-content|sftp\nhost|u@example.com|-p 65002|/home/u/public_html/wp-content\n");

        $result = $this->server->run(['list']);

        expect($result->successful())->toBeTrue()
            ->and($this->server->sitesFile())->toBe(
                "oud|u@site-serverwpmu.tempurl.host||site/public_html/wp-content|sftp|wpmudev|\n".
                "host|u@example.com|-p 65002|/home/u/public_html/wp-content|ssh|hostinger|\n"
            );
    });

    it('geeft sites als JSON met lokale en live gegevens', function (): void {
        $sites = json_decode($this->server->run(['list', '--json'])->output(), true);

        expect($sites)->toHaveCount(1)
            ->and($sites[0])->toMatchArray([
                'name' => 'demo',
                'mode' => 'ssh',
                'provider' => 'other',
                'live_host' => 'demo.test',
                'local_url' => 'https://demo.ddev.site',
                'built' => false,
                'git' => null,
            ]);
    });

    it('voegt een site handmatig toe, werkt hem bij en haalt hem weg', function (): void {
        $add = $this->server->run(['add', 'klant', 'sftp://wesley@klant.tempurl.host', '--remote', 'site/public_html/wp-content', '--mode', 'sftp', '--url', 'https://klant.nl']);
        expect($add->successful())->toBeTrue($add->errorOutput())
            ->and($this->server->sitesFile())->toContain("klant|wesley@klant.tempurl.host||site/public_html/wp-content|sftp|wpmudev|https://klant.nl|wordpress\n");

        $this->server->run(['update', 'klant', '--provider', 'hostinger'])->throw();
        expect($this->server->sitesFile())->toContain('|sftp|hostinger|https://klant.nl');

        $this->server->run(['remove', 'klant'])->throw();
        expect($this->server->sitesFile())->not->toContain('klant');
    });

    it('weigert ongeldige waarden', function (array $arguments, string $message): void {
        $result = $this->server->run(['update', 'demo', ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message);
    })->with([
        'modus' => [['--mode', 'ftp'], 'Modus moet ssh of sftp zijn'],
        'provider' => [['--provider', 'aws'], 'Provider moet'],
        'scheidingsteken' => [['--remote', 'pad|kapot'], 'niet toegestaan'],
        'naam' => [['--name', 'naam met spatie'], 'Naam mag alleen'],
    ]);
});

describe('naar live zetten', function (): void {
    beforeEach(function (): void {
        $this->wpContent = $this->server->buildLocalSite();
        file_put_contents($this->wpContent.'/themes/demo/functions.php', "<?php // telefoon 0638386827\n");
        file_put_contents($this->wpContent.'/themes/demo/nieuw.php', "<?php // nieuw\n");
        $this->server->git($this->wpContent, ['add', '-A']);
        $this->server->git($this->wpContent, ['commit', '-qm', 'Telefoonnummer aangepast']);
    });

    it('toont in een proefrun wat er live gaat zonder iets te veranderen', function (): void {
        $result = $this->server->run(['push', 'demo', '-n']);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('themes/demo/functions.php', 'themes/demo/nieuw.php', 'Proefrun')
            ->and($result->output())->not->toContain('style.css')
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('020')
            ->and($this->server->backups())->toBeEmpty();
    });

    it('zet alleen gewijzigde bestanden live en bewaart de oude live-versies', function (): void {
        $result = $this->server->run(['push', 'demo', '--yes', '--no-flush']);

        expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('0638386827')
            ->and($this->server->readFile('themes/demo/nieuw.php'))->not->toBeNull()
            ->and($this->server->readFile('.gitignore'))->toBeNull();

        $backup = $this->server->sites.'/.wpopen-backups/demo/'.$this->server->backups()[0];
        expect(file_get_contents($backup.'/live-files/themes/demo/functions.php'))->toContain('020')
            ->and(file_get_contents($backup.'/new-files'))->toBe("themes/demo/nieuw.php\n");

        $git = fn (array $arguments): string => trim($this->server->git($this->wpContent, $arguments));
        expect($git(['rev-parse', 'deployed']))->toBe($git(['rev-parse', 'main']))
            ->and($git(['rev-parse', 'main']))->toBe($git(['rev-parse', 'dev']))
            ->and($git(['branch', '--show-current']))->toBe('dev');
    });

    it('stopt als live intussen buiten wpopen om is aangepast', function (): void {
        $this->server->writeFile('themes/demo/functions.php', "<?php // hotfix van een collega\n");

        $result = $this->server->run(['push', 'demo', '--yes', '--no-flush']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('Live is aangepast', 'themes/demo/functions.php')
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('hotfix')
            ->and(trim($this->server->git($this->wpContent, ['rev-parse', 'main'])))
            ->not->toBe(trim($this->server->git($this->wpContent, ['rev-parse', 'dev'])));
    });

    it('overschrijft live-wijzigingen alleen met --force', function (): void {
        $this->server->writeFile('themes/demo/functions.php', "<?php // hotfix van een collega\n");

        $result = $this->server->run(['push', 'demo', '--yes', '--no-flush', '--force']);

        expect($result->successful())->toBeTrue()
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('0638386827');
    });

    it('draait een push terug vanuit de back-up', function (): void {
        $this->server->run(['push', 'demo', '--yes', '--no-flush'])->throw();
        $backup = $this->server->backups()[0];

        $result = $this->server->run(['restore', 'demo', $backup, '--live-files', '--yes']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('020')
            ->and($this->server->readFile('themes/demo/nieuw.php'))->toBeNull()
            ->and(trim($this->server->git($this->wpContent, ['rev-parse', 'deployed'])))
            ->toBe(trim($this->server->git($this->wpContent, ['rev-list', '--max-parents=0', 'main'])));
    });

    it('commit openstaande wijzigingen met een bericht en zet ze live', function (): void {
        file_put_contents($this->wpContent.'/themes/demo/style.css', "/* Theme Name: Demo 2 */\n");

        expect($this->server->run(['push', 'demo', '--yes', '--no-flush'])->failed())->toBeTrue();

        $result = $this->server->run(['push', 'demo', '--yes', '--no-flush', '-m', 'Stijl bijgewerkt']);

        expect($result->successful())->toBeTrue()
            ->and($this->server->readFile('themes/demo/style.css'))->toContain('Demo 2')
            ->and($this->server->git($this->wpContent, ['log', '-1', '--format=%s', 'main']))->toContain('Stijl bijgewerkt');
    });

    it('vraagt zonder --yes om bevestiging en doet dan niets', function (): void {
        $result = $this->server->run(['push', 'demo', '--no-flush']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('Bevestiging nodig')
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('020');
    });
});

describe('lokaal herstellen', function (): void {
    it('zet dev terug naar main en bewaart het werk in een backup-branch', function (): void {
        $wpContent = $this->server->buildLocalSite();
        file_put_contents($wpContent.'/themes/demo/functions.php', "<?php // half af\n");

        $result = $this->server->run(['reset', 'demo', '--yes']);

        $branches = $this->server->git($wpContent, ['branch', '--list', 'backup/*']);
        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(file_get_contents($wpContent.'/themes/demo/functions.php'))->toContain('020')
            ->and($branches)->toContain('backup/dev-')
            ->and($this->server->git($wpContent, ['show', trim(str_replace('*', '', $branches)).':themes/demo/functions.php']))->toContain('half af');
    });
});

describe('controles', function (): void {
    it('test SSH en WP-CLI op de server', function (): void {
        $checks = collect(json_decode($this->server->run(['test', 'demo', '--json'])->output(), true))->keyBy('key');

        expect($checks['connection']['status'])->toBe('ok')
            ->and($checks['wpcli'])->toMatchArray(['status' => 'ok', 'detail' => 'WordPress 6.8.1'])
            ->and($checks['local']['status'])->toBe('skip');
    });

    it('voert WP-CLI op live uit in de juiste map', function (): void {
        $this->server->run(['wp', 'demo', '--live', 'plugin', 'list'])->throw();

        expect(file_get_contents($this->server->log))->toContain("wp --path={$this->server->remoteRoot} plugin list");
    });
});

describe('lokale sites', function (): void {
    beforeEach(function (): void {
        file_put_contents($this->server->home.'/.config/wpsites/sites', "nieuw||||local||\n", FILE_APPEND);
    });

    it('geeft een lokale site zonder live-gegevens door aan de app', function (): void {
        $sites = collect(json_decode($this->server->run(['list', '--json'])->output(), true))->keyBy('name');

        expect($sites['nieuw'])->toMatchArray([
            'local_only' => true,
            'mode' => 'local',
            'live_host' => null,
            'live_site_url' => null,
        ])->and($sites['demo']['local_only'])->toBeFalse();
    });

    it('weigert live-acties en wijst naar verhuizen', function (array $arguments): void {
        $result = $this->server->run($arguments);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('lokale site zonder live-server', 'wpopen migrate nieuw');
    })->with([
        'push' => [['push', 'nieuw', '--yes']],
        'ophalen' => [['pull', 'nieuw', '--code']],
        'live cache' => [['cache', 'nieuw', '--live']],
        'ssh' => [['ssh', 'nieuw']],
    ]);

    it('weigert een naam die al bestaat', function (): void {
        $result = $this->server->run(['new', 'demo']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain("Er bestaat al een site 'demo'");
    });
});

describe('verhuizen', function (): void {
    beforeEach(function (): void {
        $this->wpContent = $this->server->buildLocalSite();
        mkdir($this->wpContent.'/mu-plugins');
        file_put_contents($this->wpContent.'/mu-plugins/wpopen-local.php', "<?php // lokaal\n");
        $this->server->writeFile('uploads/2026/10/foto.jpg', 'jpg');

        $this->destination = $this->server->root.'/nieuw/public_html/wp-content';
        mkdir($this->destination.'/plugins/host-plugin', 0777, true);
        file_put_contents(dirname($this->destination).'/wp-config.php', "<?php \$table_prefix = 'wp_';\n");
        file_put_contents($this->destination.'/plugins/host-plugin/host.php', "<?php // van de host\n");
    });

    it('zet code, uploads en database in één keer op een nieuwe server', function (): void {
        $result = $this->server->run([
            'migrate', 'demo', '-p', '2222', 'nieuw@nieuw.example.test',
            '--url', 'https://nieuw.example.test', '--remote', $this->destination, '--keep-old', '--yes',
        ]);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and(file_get_contents($this->destination.'/themes/demo/functions.php'))->toContain('telefoon 020')
            ->and(file_get_contents($this->destination.'/uploads/2026/10/foto.jpg'))->toBe('jpg')
            ->and($this->destination.'/plugins/host-plugin/host.php')->toBeFile()
            ->and($this->destination.'/mu-plugins/wpopen-local.php')->not->toBeFile()
            ->and($this->destination.'/.git')->not->toBeDirectory()
            ->and($this->server->imported())->toStartWith("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n")->toContain('lokaal_options', 'https://nieuw.example.test')
            ->and($this->server->calls())->toContain('config set table_prefix lokaal_', '--export=.wpopen-migrate.sql')
            ->and($this->server->sitesFile())
            ->toContain("demo|nieuw@nieuw.example.test|-p 2222|{$this->destination}|ssh|other|https://nieuw.example.test|wordpress\n")
            ->toContain("demo-oud|wesley@demo.test||{$this->server->remote}|ssh|other|https://demo.test|wordpress\n")
            ->and(trim($this->server->git($this->wpContent, ['rev-parse', 'deployed'])))->toBe(trim($this->server->git($this->wpContent, ['rev-parse', 'dev'])))
            ->and(collect($this->server->backups())->last())->toEndWith('-migrate');
    });

    it('laat de tabelprefix staan als die al overeenkomt', function (): void {
        $this->server->run([
            'migrate', 'demo', 'nieuw@nieuw.example.test', '--url', 'https://nieuw.example.test', '--remote', $this->destination, '--yes',
        ], ['FAKE_REMOTE_PREFIX' => 'lokaal_'])->throw();

        expect($this->server->calls())->not->toContain('config set table_prefix')
            ->and($this->server->sitesFile())->not->toContain('demo-oud');
    });

    it('vraagt om bevestiging en het adres van de nieuwe site', function (array $extra, string $message): void {
        $result = $this->server->run(['migrate', 'demo', 'nieuw@nieuw.example.test', '--remote', $this->destination, ...$extra]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message)
            ->and($this->destination.'/themes/demo')->not->toBeDirectory();
    })->with([
        'zonder adres' => [[], '--url https://'],
        'zonder bevestiging' => [['--url', 'https://nieuw.example.test'], 'Bevestiging nodig'],
    ]);

    it('zet live om naar het definitieve domein', function (): void {
        $result = $this->server->run(['domain', 'demo', 'https://klant.example.test', '--yes']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($this->server->calls())->toContain('search-replace //tijdelijk.example.test //klant.example.test')
            ->and($this->server->sitesFile())->toContain('|ssh|other|https://klant.example.test');
    });
});

describe('sites zoeken op een server', function (): void {
    beforeEach(function (): void {
        $this->account = $this->server->home.'/domains';
        foreach (['klant.nl/public_html/wp-content', 'klant.nl/public_html/old files/wp-content'] as $path) {
            mkdir($this->account.'/'.$path, 0777, true);
        }
    });

    it('slaat een kopie over die naar hetzelfde adres wijst en houdt de gekozen naam', function (): void {
        $result = $this->server->run(['add', 'rijschool', '-p', '65002', 'u123@1.2.3.4']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($result->output())->toContain('overgeslagen', 'old files')
            ->and($this->server->sitesFile())
            ->toContain("rijschool|u123@1.2.3.4|-p 65002|{$this->account}/klant.nl/public_html/wp-content|ssh|hostinger|https://tijdelijk.example.test|wordpress\n")
            ->not->toContain('old files');
    });

    it('noemt meerdere echte sites op één account naar hun domein', function (): void {
        mkdir($this->account.'/tweede.test/public_html/wp-content', 0777, true);

        $this->server->run(['add', 'rijschool', 'u123@1.2.3.4'])->throw();

        expect($this->server->sitesFile())
            ->toContain("klant.nl|u123@1.2.3.4||{$this->account}/klant.nl/public_html/wp-content|")
            ->toContain("tweede.test|u123@1.2.3.4||{$this->account}/tweede.test/public_html/wp-content|ssh|other|https://tweede.test|wordpress\n")
            ->not->toContain('rijschool-');
    });
});

describe('Laravel', function (): void {
    it('herkent een Laravel-project en neemt het adres uit de .env over', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($this->server->home.'/.config/wpsites/sites', "demo|wesley@demo.test||{$this->server->remote}|ssh|other|https://demo.test\n");
        File::deleteDirectory($this->server->remote);
        File::deleteDirectory($site['local']);

        $result = $this->server->run(['add', 'webshop', 'u1@shop.test']);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($result->output())->toContain('webshop (Laravel)')
            ->and($this->server->sitesFile())->toContain("webshop|u1@shop.test||{$site['remote']}|ssh|other|https://shop.test|laravel\n");

        $json = collect(json_decode($this->server->run(['list', '--json'])->output(), true))->keyBy('name');
        expect($json['webshop'])->toMatchArray(['type' => 'laravel', 'root' => $site['remote']]);
    });

    it('leest de database van live zonder het wachtwoord als argument mee te geven', function (): void {
        $this->server->laravelSite();

        $this->server->run(['backup', 'shop', '--live-db'])->throw();

        expect($this->server->calls())->toContain('mysqldump --single-transaction', '-u shop shop (MYSQL_PWD=geheim wachtwoord)')
            ->not->toContain('-pgeheim');
    });

    it('zet code live en doet daarna wat een Laravel-deploy nodig heeft', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/app/Http/Controllers/HomeController.php', "<?php // nieuw\n");
        file_put_contents($site['local'].'/composer.lock', '{"v": 2}');
        File::ensureDirectoryExists($site['local'].'/database/migrations');
        file_put_contents($site['local'].'/database/migrations/2026_10_08_000000_create_orders.php', "<?php // migratie\n");
        $this->server->git($site['local'], ['add', '-A']);
        $this->server->git($site['local'], ['commit', '-qm', 'Bestellingen']);

        $preview = $this->server->run(['push', 'shop', '-n']);
        expect($preview->output())->toContain('composer install --no-dev', 'php artisan migrate --force');

        $result = $this->server->run(['push', 'shop', '--yes']);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and(file_get_contents($site['remote'].'/app/Http/Controllers/HomeController.php'))->toBe("<?php // nieuw\n")
            ->and($site['remote'].'/database/migrations/2026_10_08_000000_create_orders.php')->toBeFile()
            ->and($this->server->calls())->toContain('mysqldump', 'composer install --no-dev', 'artisan migrate --force --no-interaction', 'artisan optimize:clear')
            ->and(collect($this->server->backups('shop'))->last())->toEndWith('-push');
    });

    it('maakt een lokale .env met DDEV-database en zonder betaalsleutels van live', function (): void {
        $site = $this->server->laravelSite();
        mkdir($site['local'].'/.ddev');

        $this->server->run(['fix', 'shop'])->throw();
        $env = file_get_contents($site['local'].'/.env');

        expect($env)->toContain("APP_URL=https://shop.ddev.site\n", "DB_HOST=db\n", "DB_PASSWORD=db\n", "APP_ENV=local\n", 'MAIL_MAILER=log', "STRIPE_SECRET=\n")
            ->not->toContain('sk_live_123')
            ->not->toContain('geheim wachtwoord')
            ->and(fileperms($site['local'].'/.env') & 0777)->toBe(0600);
    });

    it('draait artisan op live met losse argumenten', function (): void {
        $this->server->laravelSite();

        $this->server->run(['artisan', 'shop', '--live', '--', 'route:list', '--path=admin users'])->throw();

        expect($this->server->calls())->toContain('artisan route:list --path=admin users --no-interaction');
    });

    it('weigert WordPress-acties bij een Laravel-site', function (): void {
        $this->server->laravelSite();

        $result = $this->server->run(['wp', 'shop', '--live', 'plugin', 'list']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('Laravel-site');
    });
});

it('toont de hulp van het installatiescript zonder iets te installeren', function (): void {
    $result = Process::run(['bash', base_path('bin/install-mint'), '--help']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('curl -fsSL https://raw.githubusercontent.com/ICTtrying/bd-deck/main/bin/install-mint | bash');
});
