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

    it('meldt live-wijzigingen al in de proefrun, zonder iets te veranderen', function (): void {
        $this->server->writeFile('themes/demo/functions.php', "<?php // hotfix van een collega\n");

        $preview = $this->server->run(['push', 'demo', '-n']);

        expect($preview->successful())->toBeTrue($preview->errorOutput())
            ->and($preview->output())->toContain("Op live aangepast sinds de laatste sync:\n    themes/demo/functions.php")
            ->and($this->server->readFile('themes/demo/functions.php'))->toContain('hotfix')
            ->and($this->server->backups())->toBe([]);
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

    it('zet Enfold en de WPMU DEV-plugins uit de premium-map in een lokale site, de nieuwste versie per plugin', function (): void {
        $wpContent = $this->server->buildLocalSite();
        $premium = $this->server->root.'/premium';
        mkdir($premium);

        $zip = function (string $path, array $files, int $mtime) use ($premium): void {
            $archive = new ZipArchive;
            $archive->open($premium.'/'.$path, ZipArchive::CREATE);
            foreach ($files as $name => $contents) {
                $archive->addFromString($name, $contents);
            }
            $archive->close();
            touch($premium.'/'.$path, $mtime);
        };

        // ThemeForest-pakket: documentatie plus de echte thema-zip
        $zip('inner-enfold.zip', ['enfold/style.css' => '/* Theme Name: Enfold */
', 'enfold/functions.php' => '<?php'], 1000);
        $inner = file_get_contents($premium.'/inner-enfold.zip');
        unlink($premium.'/inner-enfold.zip');
        $zip('Enfold Package.zip', ['documentation/readme.txt' => 'lees mij', 'enfold.zip' => $inner], 1000);
        $zip('wpmudev-updates-4.0.zip', ['wpmudev-updates/plugin.php' => '<?php // versie oud'], 1000);
        $zip('wpmudev-updates-4.1.zip', ['wpmudev-updates/plugin.php' => '<?php // versie nieuw'], 2000);
        $zip('smush-pro.zip', ['wp-smush-pro/smush.php' => '<?php'], 1500);

        $result = $this->server->run(['premium', 'demo'], ['WPO_PREMIUM_DIR' => $premium]);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($wpContent.'/themes/enfold/style.css')->toBeFile()
            ->and(file_get_contents($wpContent.'/plugins/wpmudev-updates/plugin.php'))->toContain('versie nieuw')
            ->and($wpContent.'/plugins/wp-smush-pro/smush.php')->toBeFile()
            ->and($this->server->calls())->toContain('ddev wp theme activate enfold', 'ddev wp plugin activate wpmudev-updates', 'ddev wp plugin activate wp-smush-pro');
    });

    it('meldt netjes dat de premium-map leeg is', function (): void {
        $this->server->buildLocalSite();

        $result = $this->server->run(['premium', 'demo'], ['WPO_PREMIUM_DIR' => $this->server->root.'/bestaat-niet']);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('Geen Enfold of WPMU DEV-plugins gevonden');
    });

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

    it('vindt WordPress en Laravel op hetzelfde account, met de losse webmap van Hostinger', function (): void {
        $web = $this->account.'/shop.test/public_html';
        File::ensureDirectoryExists($web.'/App');
        file_put_contents($web.'/App/artisan', "<?php\n");
        file_put_contents($web.'/App/composer.json', '{"require": {"laravel/framework": "^12.0"}}');
        file_put_contents($web.'/App/.env', "APP_URL=https://shop.test\n");
        file_put_contents($web.'/index.php', "<?php require __DIR__.'/App/vendor/autoload.php';\n");

        $result = $this->server->run(['add', 'rijschool', '-p', '65002', 'u123@1.2.3.4']);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($this->server->sitesFile())
            ->toContain("klant.nl|u123@1.2.3.4|-p 65002|{$this->account}/klant.nl/public_html/wp-content|ssh|hostinger|https://tijdelijk.example.test|wordpress\n")
            ->toContain("shop.test|u123@1.2.3.4|-p 65002|{$web}/App|ssh|hostinger|https://shop.test|laravel|{$web}\n");
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

    it('zet public/ live in de losse webmap en laat de index.php van live met rust', function (): void {
        $site = $this->server->laravelSite();
        $web = dirname($site['remote']).'/public_html';
        File::ensureDirectoryExists($web);
        file_put_contents($web.'/index.php', "<?php // live, wijst naar ../laravel\n");
        file_put_contents($this->server->home.'/.config/wpsites/sites', str_replace("|laravel\n", "|laravel|{$web}\n", $this->server->sitesFile()));

        File::ensureDirectoryExists($site['local'].'/public/images');
        file_put_contents($site['local'].'/public/index.php', "<?php // standaard\n");
        file_put_contents($site['local'].'/public/images/logo.jpg', 'logo');
        file_put_contents($site['local'].'/routes/web.php', "<?php // nieuw\n");
        $this->server->git($site['local'], ['add', '-A']);
        $this->server->git($site['local'], ['commit', '-qm', 'Logo']);

        $result = $this->server->run(['push', 'shop', '--yes']);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and(file_get_contents($web.'/images/logo.jpg'))->toBe('logo')
            ->and(file_get_contents($web.'/index.php'))->toBe("<?php // live, wijst naar ../laravel\n")
            ->and(file_get_contents($site['remote'].'/routes/web.php'))->toBe("<?php // nieuw\n")
            ->and($site['remote'].'/public')->not->toBeDirectory();

        $backup = collect($this->server->backups('shop'))->last();
        $restore = $this->server->run(['restore', 'shop', $backup, '--live-files', '--yes']);

        expect($restore->successful())->toBeTrue($restore->output().$restore->errorOutput())
            ->and($web.'/images/logo.jpg')->not->toBeFile()
            ->and(file_get_contents($site['remote'].'/routes/web.php'))->toBe("<?php // routes\n");
    });

    it('haalt public/ uit de webmap op live, zonder de map van het project zelf', function (): void {
        $site = $this->server->laravelSite();
        $web = dirname($site['remote']);
        rename($site['remote'], $web.'/tmp-laravel');
        File::ensureDirectoryExists($web.'/public_html/images');
        rename($web.'/tmp-laravel', $web.'/public_html/Shop');
        $project = $web.'/public_html/Shop';
        File::ensureDirectoryExists($project.'/public/oud');
        file_put_contents($project.'/public/index.php', "<?php // standaard\n");
        file_put_contents($project.'/public/oud/verouderd.css', 'oud');
        file_put_contents($web.'/public_html/index.php', "<?php require __DIR__.'/Shop/vendor/autoload.php';\n");
        file_put_contents($web.'/public_html/images/logo.jpg', 'logo');
        file_put_contents($this->server->home.'/.config/wpsites/sites', str_replace("|{$site['remote']}|ssh|other|https://shop.test|laravel\n", "|{$project}|ssh|other|https://shop.test|laravel|{$web}/public_html\n", $this->server->sitesFile()));

        $this->server->run(['backup', 'shop', '--live-files'])->throw();
        $full = $this->server->sites.'/.wpopen-backups/shop/'.collect($this->server->backups('shop'))->last().'/live-full';

        expect(file_get_contents($full.'/public/images/logo.jpg'))->toBe('logo')
            ->and(file_get_contents($full.'/public/index.php'))->toBe("<?php // standaard\n")
            ->and($full.'/public/Shop')->not->toBeDirectory()
            ->and($full.'/public/oud')->not->toBeDirectory()
            ->and($full.'/routes/web.php')->toBeFile();
    });

    it('toont beschikbare Composer- en npm-updates', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/package.json', '{"scripts": {"build": "vite build"}}');

        $result = $this->server->run(['updates', 'shop', '--json'], [
            'FAKE_COMPOSER_OUTDATED' => '{"installed": [{"name": "laravel/framework", "version": "v12.1.0", "latest": "v12.4.0", "latest-status": "semver-safe-update"}, {"name": "pestphp/pest", "version": "v3.0.0", "latest": "v4.0.0", "latest-status": "update-possible"}]}',
            'FAKE_NPM_OUTDATED' => '{"vite": {"current": "6.0.0", "wanted": "6.2.0", "latest": "7.0.0"}}',
        ]);

        expect(json_decode($result->output(), true))->toBe([
            'composer' => [
                ['name' => 'laravel/framework', 'version' => '12.1.0', 'update_version' => '12.4.0', 'major' => false],
                ['name' => 'pestphp/pest', 'version' => '3.0.0', 'update_version' => '4.0.0', 'major' => true],
            ],
            'npm' => [
                ['name' => 'vite', 'version' => '6.0.0', 'update_version' => '6.2.0', 'major' => false],
            ],
        ]);
    });

    it('werkt pakketten bij na een back-up en commit ze los op dev', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/composer.json', '{"require": {"laravel/framework": "^12.0"}}');
        file_put_contents($site['local'].'/composer.lock', '{"packages": [{"name": "laravel/framework", "version": "v12.1.0"}, {"name": "symfony/console", "version": "v7.0.0"}]}');
        $this->server->git($site['local'], ['commit', '-qam', 'Lock']);

        $result = $this->server->run(['upgrade', 'shop'], [
            'FAKE_COMPOSER_LOCK' => '{"packages": [{"name": "laravel/framework", "version": "v12.4.0"}, {"name": "symfony/console", "version": "v7.1.0"}]}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($result->output())->toContain('composer laravel/framework 12.1.0 → 12.4.0', 'composer: en 1 onderliggende pakket(ten)', 'Lokale site werkt (HTTP 200)')
            ->and($this->server->calls())->toContain('ddev export-db', 'ddev composer update --with-all-dependencies')
            ->and($this->server->git($site['local'], ['log', '-1', '--format=%s%n%b']))->toContain('Pakketten bijgewerkt', 'laravel/framework 12.1.0 → 12.4.0')
            ->and(collect($this->server->backups('shop'))->last())->toEndWith('-upgrade');
    });

    it('tilt met --major ook de versie-eisen naar nieuwe hoofdversies', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/composer.json', '{"require": {"laravel/framework": "^12.0"}, "require-dev": {"phpunit/phpunit": "^11.0"}}');
        file_put_contents($site['local'].'/composer.lock', '{"packages": [{"name": "laravel/framework", "version": "v12.1.0"}]}');
        $this->server->git($site['local'], ['commit', '-qam', 'Lock']);

        $result = $this->server->run(['upgrade', 'shop', '--major'], [
            'FAKE_COMPOSER_OUTDATED' => '{"installed": [{"name": "laravel/framework", "version": "v12.1.0", "latest": "v13.0.0", "latest-status": "update-possible"}, {"name": "phpunit/phpunit", "version": "11.5.0", "latest": "12.0.0", "latest-status": "update-possible"}]}',
            'FAKE_COMPOSER_LOCK' => '{"packages": [{"name": "laravel/framework", "version": "v13.0.0"}]}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        $composer = json_decode((string) file_get_contents($site['local'].'/composer.json'), true);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($composer['require']['laravel/framework'])->toBe('^13.0.0')
            ->and($composer['require-dev']['phpunit/phpunit'])->toBe('^12.0.0')
            ->and($this->server->git($site['local'], ['log', '-1', '--format=%b']))->toContain('laravel/framework 12.1.0 → 13.0.0');
    });

    it('slaat bij --major een pakket over dat niet past bij de andere pakketten', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/composer.json', '{"require": {"laravel/framework": "^12.0", "guzzlehttp/guzzle": "^7.0"}}');
        $this->server->git($site['local'], ['commit', '-qam', 'Eisen']);

        $result = $this->server->run(['upgrade', 'shop', '--major'], [
            'FAKE_COMPOSER_OUTDATED' => '{"installed": [{"name": "guzzlehttp/guzzle", "version": "7.1.0", "latest": "8.0.0", "latest-status": "update-possible"}, {"name": "laravel/framework", "version": "v12.1.0", "latest": "v13.0.0", "latest-status": "update-possible"}]}',
            'FAKE_COMPOSER_CONFLICT' => 'guzzle": "^8',
            'FAKE_COMPOSER_LOCK' => '{"packages": [{"name": "laravel/framework", "version": "v13.0.0"}]}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        $composer = json_decode((string) file_get_contents($site['local'].'/composer.json'), true);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($result->output())->toContain('guzzlehttp/guzzle blijft op ^7.0')
            ->and($composer['require']['laravel/framework'])->toBe('^13.0.0')
            ->and($composer['require']['guzzlehttp/guzzle'])->toBe('^7.0');
    });

    it('werkt met --package alleen dat ene pakket bij, ook als het een hoofdversie is', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/composer.json', '{"require": {"laravel/framework": "^12.0", "guzzlehttp/guzzle": "^7.0"}}');
        $this->server->git($site['local'], ['commit', '-qam', 'Eisen']);

        $result = $this->server->run(['upgrade', 'shop', '--package', 'composer:laravel/framework'], [
            'FAKE_COMPOSER_OUTDATED' => '{"installed": [{"name": "laravel/framework", "version": "v12.1.0", "latest": "v13.0.0", "latest-status": "update-possible"}, {"name": "guzzlehttp/guzzle", "version": "7.1.0", "latest": "8.0.0", "latest-status": "update-possible"}]}',
            'FAKE_COMPOSER_LOCK' => '{"packages": [{"name": "laravel/framework", "version": "v13.0.0"}]}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        $composer = json_decode((string) file_get_contents($site['local'].'/composer.json'), true);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($this->server->calls())->toContain('ddev composer update laravel/framework --with-all-dependencies')
            ->and($composer['require']['laravel/framework'])->toBe('^13.0.0')
            ->and($composer['require']['guzzlehttp/guzzle'])->toBe('^7.0')
            ->and($this->server->git($site['local'], ['log', '-1', '--format=%s']))->toContain('Pakket bijgewerkt: laravel/framework');
    });

    it('werkt met meerdere --package-opties pakketten die elkaar nodig hebben in één npm-run bij', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/package.json', '{"devDependencies": {"vite": "^7.0.0", "laravel-vite-plugin": "^2.0.0"}}');
        $this->server->git($site['local'], ['add', '-A']);
        $this->server->git($site['local'], ['commit', '-qm', 'npm']);

        $result = $this->server->run(['upgrade', 'shop', '--package', 'npm:vite', '--package', 'npm:laravel-vite-plugin'], [
            'FAKE_NPM_OUTDATED' => '{"vite": {"current": "7.0.0", "wanted": "7.0.0", "latest": "8.0.0"}, "laravel-vite-plugin": {"current": "2.0.0", "wanted": "2.0.0", "latest": "3.0.0"}}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($this->server->calls())->toContain('ddev npm install vite@^8.0.0 laravel-vite-plugin@^3.0.0');
    });

    it('weigert een ongeldige pakketnaam bij --package', function (): void {
        $this->server->laravelSite();

        $result = $this->server->run(['upgrade', 'shop', '--package', 'composer:x;rm']);

        expect($result->failed())->toBeTrue()->and($result->errorOutput())->toContain('Ongeldig pakket');
    });

    it('laat de versie-eisen met rust zonder --major', function (): void {
        $site = $this->server->laravelSite();
        $this->server->run(['upgrade', 'shop'], [
            'FAKE_COMPOSER_OUTDATED' => '{"installed": [{"name": "laravel/framework", "version": "v12.1.0", "latest": "v13.0.0", "latest-status": "update-possible"}]}',
            'FAKE_LOCAL_HTTP' => '200',
        ]);

        expect(file_get_contents($site['local'].'/composer.json'))->toContain('"^12.0"');
    });

    it('zet alles terug als de lokale site na de update een serverfout geeft', function (): void {
        $site = $this->server->laravelSite();

        $result = $this->server->run(['upgrade', 'shop'], [
            'FAKE_COMPOSER_LOCK' => '{"packages": [{"name": "laravel/framework", "version": "v13.0.0"}]}',
            'FAKE_LOCAL_HTTP' => '500',
        ]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('serverfout (HTTP 500)', 'Er is niets veranderd')
            ->and(file_get_contents($site['local'].'/composer.lock'))->toBe('{"v": 1}')
            ->and($this->server->git($site['local'], ['log', '-1', '--format=%s']))->toBe("Start: live-staat\n")
            ->and($this->server->calls())->toContain('ddev composer install');
    });

    it('werkt niet bij als er nog eigen wijzigingen open staan', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['local'].'/routes/web.php', "<?php // half af\n");

        $result = $this->server->run(['upgrade', 'shop']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('niet-gecommitte wijzigingen')
            ->and($this->server->calls())->not->toContain('composer update');
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
