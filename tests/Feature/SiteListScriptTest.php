<?php

use Tests\Support\FakeServer;

beforeEach(function (): void {
    $this->server = FakeServer::create();
    $this->sitesFile = $this->server->home.'/.config/wpsites/sites';
});

afterEach(function (): void {
    $this->server->destroy();
});

describe('export-sites', function (): void {
    it('laat persoonlijke sleutelpaden en lokale sites weg', function (): void {
        file_put_contents($this->sitesFile, implode("\n", [
            'klant|u1@klant.test|-p 65002 -i /home/wesley/.ssh/klant -o IdentityFile=~/.ssh/ander -A|/home/u1/public_html/wp-content|sftp|hostinger|https://klant.nl|wordpress',
            'lokaal||||local|other||wordpress',
            'shop|u2@shop.test||/home/u2/shop|ssh|other||laravel|/home/u2/public_html',
        ])."\n", FILE_APPEND);

        $result = $this->server->run(['export-sites']);
        $export = json_decode($result->output(), true);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($export)->toMatchArray(['format' => 'bd-deck-sites', 'version' => 1])
            ->and($export['exported_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
            ->and(array_column($export['sites'], 'name'))->toBe(['demo', 'klant', 'shop'])
            ->and($export['sites'][1])->toBe([
                'name' => 'klant', 'target' => 'u1@klant.test', 'ssh_options' => '-p 65002 -A',
                'remote' => '/home/u1/public_html/wp-content', 'mode' => 'sftp', 'provider' => 'hostinger',
                'live_url' => 'https://klant.nl', 'type' => 'wordpress', 'public_dir' => null,
            ])
            ->and($export['sites'][2])->toMatchArray(['type' => 'laravel', 'public_dir' => '/home/u2/public_html'])
            ->and($result->output())->not->toContain('.ssh')
            ->and($result->errorOutput())->toContain('1 lokale site(s) zonder server niet meegenomen', 'persoonlijke SSH-opties weggelaten bij: klant')
            ->and($this->server->calls())->toBe('');
    });
});

describe('import-sites', function (): void {
    it('voegt nieuwe sites toe zonder met de server te verbinden', function (): void {
        $file = $this->server->siteListFile([
            ['name' => 'klant', 'target' => 'u1@klant.test', 'ssh_options' => '-p 65002', 'mode' => 'sftp', 'provider' => 'hostinger', 'live_url' => 'https://klant.nl'],
            ['name' => 'shop', 'target' => 'u2@shop.test', 'remote' => '/home/u2/shop', 'type' => 'laravel', 'public_dir' => '/home/u2/public_html'],
            ['name' => 'demo', 'target' => 'wesley@demo.test', 'remote' => $this->server->remote, 'live_url' => 'https://demo.test'],
        ]);

        $result = $this->server->run(['import-sites', $file, '--json']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(json_decode($result->output(), true))->toBe(['added' => ['klant', 'shop'], 'updated' => [], 'skipped' => ['demo'], 'invalid' => []])
            ->and($this->server->sitesFile())->toContain(
                "klant|u1@klant.test|-p 65002|/home/wesley/public_html/wp-content|sftp|hostinger|https://klant.nl|wordpress\n",
                "shop|u2@shop.test||/home/u2/shop|ssh|other||laravel|/home/u2/public_html\n",
            )
            ->and($this->server->calls())->toBe('');
    });

    it('slaat een bestaande site met andere gegevens over zonder --update', function (): void {
        $before = $this->server->sitesFile();
        $file = $this->server->siteListFile([['name' => 'demo', 'target' => 'nieuw@demo.test', 'remote' => $this->server->remote]]);

        $result = $this->server->run(['import-sites', $file]);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('= overgeslagen: demo (staat er al, andere gegevens (gebruik --update))')
            ->and($this->server->sitesFile())->toBe($before);
    });

    it('werkt met --update alleen de verbindingsgegevens bij en houdt eigen sleutel, type en lokale map', function (): void {
        $wpContent = $this->server->buildLocalSite();
        file_put_contents($this->sitesFile, "demo|wesley@demo.test|-p 22 -i /home/wesley/.ssh/eigen|{$this->server->remote}|ssh|other|https://demo.test|wordpress\n");
        $file = $this->server->siteListFile([['name' => 'demo', 'target' => 'nieuw@demo.test', 'ssh_options' => '-p 65002', 'remote' => '/home/nieuw/wp-content', 'provider' => 'hostinger', 'live_url' => 'https://demo.nl']]);

        $result = $this->server->run(['import-sites', $file, '--update', '--json']);

        expect(json_decode($result->output(), true)['updated'])->toBe(['demo'])
            ->and($this->server->sitesFile())->toBe("demo|nieuw@demo.test|-p 65002 -i /home/wesley/.ssh/eigen|/home/nieuw/wp-content|ssh|hostinger|https://demo.nl|wordpress\n")
            ->and($wpContent.'/themes/demo/style.css')->toBeFile()
            ->and($this->server->sitesFile())->not->toContain('laravel');
    });

    it('herkent dezelfde gegevens ondanks een eigen sleutelpad', function (): void {
        file_put_contents($this->sitesFile, "demo|wesley@demo.test|-i /home/wesley/.ssh/eigen|{$this->server->remote}|ssh|other||wordpress\n");
        $file = $this->server->siteListFile([['name' => 'demo', 'target' => 'wesley@demo.test', 'remote' => $this->server->remote]]);

        $result = $this->server->run(['import-sites', $file, '--update']);

        expect($result->output())->toContain('= overgeslagen: demo (zelfde gegevens)');
    });

    it('raakt een lokale site of een site van een ander type nooit aan', function (): void {
        file_put_contents($this->sitesFile, "lokaal||||local|other||wordpress\nshop|u@shop.test||/home/u/shop|ssh|other||laravel\n");
        $file = $this->server->siteListFile([['name' => 'lokaal'], ['name' => 'shop', 'target' => 'ander@shop.test']]);

        $result = $this->server->run(['import-sites', $file, '--update']);

        expect($result->output())->toContain('lokaal (lokale site met dezelfde naam)', 'shop (bestaande site is van een ander type)')
            ->and($this->server->sitesFile())->toBe("lokaal||||local|other||wordpress\nshop|u@shop.test||/home/u/shop|ssh|other||laravel\n");
    });

    it('importeert met --only alleen de gekozen sites', function (): void {
        $file = $this->server->siteListFile([['name' => 'een'], ['name' => 'twee'], ['name' => 'drie']]);

        $result = $this->server->run(['import-sites', $file, '--only', 'twee', '--only', 'vier', '--json']);

        expect(json_decode($result->output(), true))->toBe([
            'added' => ['twee'], 'updated' => [], 'skipped' => [],
            'invalid' => [['name' => 'vier', 'reason' => 'staat niet in het bestand']],
        ])
            ->and($this->server->sitesFile())->not->toContain('een|', 'drie|');
    });

    it('weigert ongeldige sites met een reden en voegt de rest toe', function (array $site, string $reason): void {
        $file = $this->server->siteListFile([['name' => 'goed'], ['name' => 'fout', ...$site]]);

        $result = $this->server->run(['import-sites', $file, '--json']);
        $summary = json_decode($result->output(), true);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($summary['added'])->toBe(['goed'])
            ->and($summary['invalid'])->toHaveCount(1)
            ->and($summary['invalid'][0]['reason'])->toContain($reason)
            ->and($this->server->sitesFile())->not->toContain('fout');
    })->with([
        'naam met spatie' => [['name' => 'fout naam'], 'naam mag alleen'],
        'scheidingsteken' => [['remote' => '/pad|kapot'], "'|' en enters"],
        'enter' => [['remote' => "/pad\nkapot"], "'|' en enters"],
        'geen tekst' => [['target' => 123], 'moet tekst zijn'],
        'server zonder gebruiker' => [['target' => 'klant.test'], 'gebruiker@host'],
        'sleutelpad' => [['ssh_options' => '-p 22 -i /home/collega/.ssh/id_ed25519'], 'persoonlijk pad'],
        'identityfile' => [['ssh_options' => '-o IdentityFile=/tmp/sleutel'], 'persoonlijk pad'],
        'shell-tekens' => [['ssh_options' => '-p 22;rm'], 'ongeldig formaat'],
        'losse waarde' => [['ssh_options' => 'ssh -p 22'], 'ongeldig formaat'],
        'pad ontbreekt' => [['remote' => ''], 'pad op de server'],
        'modus' => [['mode' => 'ftp'], 'modus moet ssh of sftp'],
        'lokale modus' => [['mode' => 'local'], 'modus moet ssh of sftp'],
        'provider' => [['provider' => 'aws'], 'provider moet'],
        'live-adres' => [['live_url' => 'javascript:alert(1)'], 'ongeldig live-adres'],
        'type' => [['type' => 'drupal'], 'type moet wordpress of laravel'],
        'public-map bij WordPress' => [['public_dir' => '/home/u/public_html'], 'alleen bij Laravel'],
    ]);

    it('meldt een dubbele naam in het bestand', function (): void {
        $file = $this->server->siteListFile([['name' => 'klant'], ['name' => 'klant', 'target' => 'ander@klant.test']]);

        $summary = json_decode($this->server->run(['import-sites', $file, '--json'])->output(), true);

        expect($summary['added'])->toBe(['klant'])
            ->and($summary['invalid'])->toBe([['name' => 'klant', 'reason' => 'staat dubbel in het bestand']]);
    });

    it('weigert een bestand dat geen sitelijst is', function (array $document, string $message): void {
        $file = $this->server->siteListFile([['name' => 'klant']], $document);

        $result = $this->server->run(['import-sites', $file]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message)
            ->and($this->server->sitesFile())->not->toContain('klant');
    })->with([
        'ander formaat' => [['format' => 'iets-anders'], 'geen sitelijst van BD Deck'],
        'nieuwere versie' => [['version' => 2], 'nieuwere versie'],
        'geen lijst' => [['sites' => 'klant'], 'geen sites'],
    ]);

    it('weigert kapotte JSON en een ontbrekend bestand', function (): void {
        file_put_contents($this->server->root.'/kapot.json', '{"format": ');

        expect($this->server->run(['import-sites', $this->server->root.'/kapot.json'])->errorOutput())->toContain('geen geldig JSON-bestand')
            ->and($this->server->run(['import-sites', $this->server->root.'/bestaat-niet.json'])->errorOutput())->toContain('Bestand niet gevonden');
    });
});
