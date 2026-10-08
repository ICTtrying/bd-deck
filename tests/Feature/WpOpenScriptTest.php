<?php

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
            ->and($this->server->sitesFile())->toContain("klant|wesley@klant.tempurl.host||site/public_html/wp-content|sftp|wpmudev|https://klant.nl\n");

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
