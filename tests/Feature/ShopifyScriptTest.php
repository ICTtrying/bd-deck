<?php

use Tests\Support\FakeShopify;

beforeEach(function (): void {
    $this->shopify = FakeShopify::create();
    $this->sites = fn (): array => json_decode($this->shopify->run(['list', '--json'])->throw()->output(), true);
    $this->site = fn (string $name = 'demo'): array => collect(($this->sites)())->firstWhere('name', $name);
    $this->lastLine = fn (string $output): array => json_decode(collect(explode("\n", trim($output)))->last(), true);
    // een push mag nooit het live thema raken: geen --live, geen --allow-live en de nep-CLI heeft geen poging gelogd
    $this->noLivePush = fn () => expect($this->shopify->calls())->not->toContain('LIVE-PUSH-POGING')->not->toContain('--live')->not->toContain('--allow-live');
});

afterEach(function (): void {
    $this->shopify->destroy();
});

describe('project toevoegen', function (): void {
    it('koppelt een bestaande themamap en toont hem in de sitelijst', function (): void {
        $path = $this->shopify->project('demo');

        $add = $this->shopify->run(['shopify', 'add', 'demo', '--store', 'https://Demo-Winkel.myshopify.com/admin', '--path', $path, '--url', 'https://klant.nl']);

        expect($add->successful())->toBeTrue($add->errorOutput())
            ->and($this->shopify->sitesFile())->toBe("demo||||shopify|shopify|https://klant.nl|shopify||demo-winkel.myshopify.com|{$path}|{$this->shopify->port}||\n");

        expect(($this->site)())->toMatchArray([
            'type' => 'shopify',
            'mode' => 'shopify',
            'provider' => 'shopify',
            'local_only' => false,
            'built' => true,
            'live_host' => 'klant.nl',
            'local_url' => 'http://127.0.0.1:'.$this->shopify->port,
            'project_dir' => $path,
            'ddev_status' => 'stopped',
        ])->and(($this->site)()['shopify'])->toMatchArray([
            'store' => 'demo-winkel.myshopify.com',
            'port' => $this->shopify->port,
            'theme_editor_sync' => false,
            'ignore' => ['config/settings_data.json', 'templates/*.json', 'sections/*.json'],
            'dev' => null,
            'last_push' => null,
        ]);
    });

    it('kiest per project een eigen vrije poort', function (): void {
        $this->shopify->addProject('een');
        $this->shopify->addProject('twee');
        $this->shopify->addProject('drie', ['--port', (string) ($this->shopify->port + 5)]);

        expect(collect(($this->sites)())->mapWithKeys(fn (array $site): array => [$site['name'] => $site['shopify']['port']])->all())
            ->toBe(['een' => $this->shopify->port, 'twee' => $this->shopify->port + 1, 'drie' => $this->shopify->port + 5]);
    });

    it('geeft een duidelijke fout bij een verkeerde invoer', function (array $arguments, string $message): void {
        $path = $this->shopify->project('demo');
        $result = $this->shopify->run(['shopify', 'add', 'demo', '--path', $path, ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message)
            ->and($this->shopify->sitesFile())->toBe('');
    })->with([
        'eigen domein' => [['--store', 'klant.nl'], '.myshopify.com-naam'],
        'geen winkel' => [[], 'Geef de winkel op'],
        'poort' => [['--store', 'demo', '--port', '80'], 'tussen 1024 en 65535'],
        'adres' => [['--store', 'demo', '--url', 'klant.nl'], 'volledig adres'],
        'patroon' => [['--store', 'demo', '--ignore', '../geheim'], 'Ongeldig patroon'],
        'host' => [['--store', 'demo', '--host', '10.0.0.1'], '127.0.0.1 of 0.0.0.0'],
        'onbekende optie' => [['--store', 'demo', '--live'], 'Onbekende optie'],
    ]);

    it('weigert een map die geen thema is en een map die niet bestaat', function (): void {
        mkdir($this->shopify->projects.'/leeg-thema', 0755, true);
        file_put_contents($this->shopify->projects.'/leeg-thema/readme.md', 'geen thema');

        $notATheme = $this->shopify->run(['shopify', 'add', 'x', '--store', 'demo', '--path', $this->shopify->projects.'/leeg-thema']);
        $missing = $this->shopify->run(['shopify', 'add', 'y', '--store', 'demo', '--path', $this->shopify->projects.'/bestaat-niet']);

        expect($notATheme->errorOutput())->toContain('geen Shopify-thema')
            ->and($missing->errorOutput())->toContain('--create')
            ->and($this->shopify->sitesFile())->toBe('');
    });

    it('weigert een dubbele naam', function (): void {
        $this->shopify->addProject('demo');

        $again = $this->shopify->run(['shopify', 'add', 'demo', '--store', 'demo', '--path', $this->shopify->projects.'/demo']);

        expect($again->failed())->toBeTrue()->and($again->errorOutput())->toContain("bestaat al een site 'demo'");
    });

    it('legt een map zonder git-repo vast als uitgangspunt', function (): void {
        $path = $this->shopify->project('demo');
        exec('rm -rf '.escapeshellarg($path.'/.git'));

        $this->shopify->run(['shopify', 'add', 'demo', '--store', 'demo', '--path', $path])->throw();

        expect($this->shopify->git($path, ['log', '-1', '--format=%s']))->toContain('Uitgangspunt')
            ->and($this->shopify->git($path, ['tag']))->toContain('deployed');
    });

    it('haalt met --create het live thema op en legt het vast als eerste commit', function (): void {
        $path = $this->shopify->projects.'/nieuw';

        $result = $this->shopify->run(['shopify', 'add', 'nieuw', '--store', FakeShopify::STORE, '--path', $path, '--create']);

        expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
            ->and(file_get_contents($path.'/templates/index.json'))->toContain('klant')
            ->and($this->shopify->git($path, ['log', '--format=%s']))->toBe('Live thema, uitgangspunt')
            ->and($this->shopify->git($path, ['tag']))->toContain('deployed')
            ->and(file_get_contents($path.'/.gitignore'))->toContain('node_modules/')
            ->and($this->shopify->sitesFile())->toContain('nieuw||||shopify|shopify||shopify||demo-winkel.myshopify.com|');
    });

    it('werkt instellingen bij en kan hernoemen', function (): void {
        $this->shopify->addProject('demo');

        $this->shopify->run(['shopify', 'update', 'demo', '--port', (string) ($this->shopify->port + 6), '--sync', '1', '--host', '0.0.0.0', '--ignore', 'config/settings_data.json', '--ignore', 'templates/*.json', '--url', 'https://nieuw.nl'])->throw();

        expect(($this->site)()['shopify'])->toMatchArray(['port' => $this->shopify->port + 6, 'theme_editor_sync' => true, 'host' => '0.0.0.0', 'ignore' => ['config/settings_data.json', 'templates/*.json'], 'ignore_is_default' => false])
            ->and(($this->site)()['live_host'])->toBe('nieuw.nl');

        $this->shopify->run(['shopify', 'update', 'demo', '--name', 'anders'])->throw();
        expect(collect(($this->sites)())->pluck('name')->all())->toBe(['anders']);
    });

    it('laat beveiliging uitzetten alleen met een bewuste keuze', function (): void {
        $this->shopify->addProject('demo');

        $this->shopify->run(['shopify', 'update', 'demo', '--ignore', 'none'])->throw();

        expect(($this->site)()['shopify'])->toMatchArray(['protection_disabled' => true, 'ignore' => []]);
    });

    it('haalt een project alleen uit de lijst en laat de themamap altijd staan', function (): void {
        $path = $this->shopify->addProject('demo');

        $remove = $this->shopify->run(['remove', 'demo', '--purge']);

        expect($remove->successful())->toBeTrue()
            ->and($remove->output())->toContain('blijft staan')
            ->and(is_file($path.'/layout/theme.liquid'))->toBeTrue()
            ->and(is_dir($path.'/.git'))->toBeTrue()
            ->and($this->shopify->sitesFile())->toBe('');
    });

    it('laat WordPress-opdrachten een Shopify-project niet aanraken', function (string $command): void {
        $this->shopify->addProject('demo');

        $result = $this->shopify->run([$command, 'demo']);

        expect($result->failed())->toBeTrue()->and($result->errorOutput())->toContain('Shopify-project');
    })->with(['pull', 'build', 'reset', 'backup', 'fix', 'push']);

    it('neemt Shopify-projecten niet mee in een export voor collega\'s', function (): void {
        $this->shopify->addProject('demo');

        $export = $this->shopify->run(['export-sites']);

        expect(json_decode($export->output(), true)['sites'])->toBe([])
            ->and($export->errorOutput())->toContain('Shopify-project(en) niet meegenomen');
    });
});

describe('dev server', function (): void {
    beforeEach(function (): void {
        $this->path = $this->shopify->addProject('demo');
    });

    it('start de dev server met een eigen poort en toont de adressen', function (): void {
        $start = $this->shopify->run(['start', 'demo']);

        expect($start->successful())->toBeTrue($start->errorOutput().$start->output())
            ->and($start->output())->toContain(
                'PREVIEW_URL=http://127.0.0.1:'.$this->shopify->port,
                'SHARE_URL=https://demo-winkel.myshopify.com/?preview_theme_id=555',
                'EDITOR_URL=https://demo-winkel.myshopify.com/admin/themes/555/editor?hr='.$this->shopify->port,
                'THEME_ID=555',
            )
            ->and($this->shopify->calls())->toContain('shopify theme dev --store demo-winkel.myshopify.com --path '.$this->path.' --port '.$this->shopify->port.' --host 127.0.0.1')
            ->and($this->shopify->calls())->not->toContain('--theme-editor-sync');

        $site = ($this->site)();
        expect($site['ddev_status'])->toBe('running')
            ->and($site['local_url'])->toBe('http://127.0.0.1:'.$this->shopify->port)
            ->and($site['shopify']['dev'])->toMatchArray([
                'url' => 'http://127.0.0.1:'.$this->shopify->port,
                'share_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=555',
                'theme_id' => 555,
            ]);
    });

    it('houdt de repo van de klant schoon', function (): void {
        $this->shopify->run(['start', 'demo'])->throw();

        expect($this->shopify->git($this->path, ['status', '--porcelain']))->toBe('')
            ->and(is_file($this->shopify->stateDirectory().'/dev'))->toBeTrue()
            ->and(is_file($this->shopify->stateDirectory().'/dev.log'))->toBeTrue();
    });

    it('stopt de dev server en alles wat hij gestart heeft', function (): void {
        $this->shopify->run(['start', 'demo'])->throw();
        $pid = (int) trim((string) shell_exec('sed -n "s/^pid=//p" '.escapeshellarg($this->shopify->stateDirectory().'/dev')));
        expect($pid)->toBeGreaterThan(1)->and(posix_kill($pid, 0))->toBeTrue();

        $stop = $this->shopify->run(['stop', 'demo']);

        expect($stop->successful())->toBeTrue()->and($stop->output())->toContain('gestopt');
        usleep(300_000);
        expect(file_exists('/proc/'.$pid))->toBeFalse()
            ->and(is_file($this->shopify->stateDirectory().'/dev'))->toBeFalse()
            ->and(($this->site)()['shopify']['dev'])->toBeNull()
            ->and(($this->site)()['ddev_status'])->toBe('stopped');
    });

    it('start niet dubbel en meldt dat hij al draait', function (): void {
        $this->shopify->run(['start', 'demo'])->throw();

        $again = $this->shopify->run(['start', 'demo']);

        expect($again->successful())->toBeTrue()
            ->and($again->output())->toContain('draait al', 'PREVIEW_URL=http://127.0.0.1:'.$this->shopify->port)
            ->and(substr_count($this->shopify->calls(), 'shopify theme dev'))->toBe(1);
    });

    it('stopt rustig als er niets draait', function (): void {
        $stop = $this->shopify->run(['stop', 'demo']);

        expect($stop->successful())->toBeTrue()->and($stop->output())->toContain('draaide niet');
    });

    it('raakt geen vreemd proces aan als het statusbestand verouderd of fout is', function (): void {
        $other = proc_open(['sleep', '30'], [], $pipes);
        $pid = proc_get_status($other)['pid'];
        mkdir($this->shopify->stateDirectory(), 0700, true);
        file_put_contents($this->shopify->stateDirectory().'/dev', "pid={$pid}\nport={$this->shopify->port}\nurl=http://127.0.0.1:{$this->shopify->port}\n");

        $stop = $this->shopify->run(['stop', 'demo']);

        expect($stop->successful())->toBeTrue()
            ->and(posix_kill($pid, 0))->toBeTrue()
            ->and(is_file($this->shopify->stateDirectory().'/dev'))->toBeFalse();

        // ook pid 1 en een bestand met rommel doen niets
        foreach (['1', '0', '-1', 'abc', ''] as $bad) {
            file_put_contents($this->shopify->stateDirectory().'/dev', "pid={$bad}\n");
            expect($this->shopify->run(['stop', 'demo'])->successful())->toBeTrue();
        }

        proc_terminate($other);
        proc_close($other);
    });

    it('geeft de poort-bezet-melding als een ander programma de poort gebruikt', function (): void {
        $listener = stream_socket_server('tcp://127.0.0.1:'.$this->shopify->port);

        $start = $this->shopify->run(['start', 'demo']);

        expect($start->failed())->toBeTrue()
            ->and($start->errorOutput())->toContain('Poort '.$this->shopify->port.' is bezet', 'andere poort')
            ->and($this->shopify->calls())->not->toContain('theme dev');

        fclose($listener);
    });

    it('legt een poortfout van de CLI zelf uit', function (): void {
        $start = $this->shopify->run(['start', 'demo'], ['FAKE_SHOPIFY_DEV_FAIL' => '1']);

        expect($start->failed())->toBeTrue()
            ->and($start->errorOutput())->toContain('Poort '.$this->shopify->port.' is al in gebruik', 'De dev server is gestopt');
    });

    it('legt ontbrekende rechten op thema\'s uit', function (): void {
        $start = $this->shopify->run(['start', 'demo'], ['FAKE_SHOPIFY_FORBIDDEN' => '1']);

        expect($start->failed())->toBeTrue()->and($start->errorOutput())->toContain("Geen toegang tot de thema's", "'Thema's'");
    });

    it('legt een verkeerde winkelnaam uit', function (): void {
        $start = $this->shopify->run(['start', 'demo'], ['FAKE_SHOPIFY_NOSTORE' => '1']);

        expect($start->failed())->toBeTrue()->and($start->errorOutput())->toContain('bestaat niet of is niet bereikbaar', 'demo-winkel.myshopify.com');
    });

    it('toont de inloglink en code in plaats van te blijven hangen', function (): void {
        $start = $this->shopify->run(['start', 'demo'], ['FAKE_SHOPIFY_LOGGED_OUT' => '1', 'FAKE_SHOPIFY_DEV_DELAY' => '3']);

        expect($start->successful())->toBeTrue($start->errorOutput().$start->output())
            ->and($start->output())->toContain(
                'Inloggen bij Shopify nodig',
                'LOGIN_URL=https://accounts.shopify.com/activate-with-code?device_code%5Buser_code%5D=ABCD-EFGH',
                'LOGIN_CODE=ABCD-EFGH',
                'PREVIEW_URL=http://127.0.0.1:'.$this->shopify->port,
            )
            ->and(substr_count($start->output(), 'LOGIN_URL='))->toBe(1);
    });

    it('geeft het storefront-wachtwoord alleen via de omgeving mee en laat het nooit in een log komen', function (): void {
        $start = $this->shopify->run(['start', 'demo'], ['SHOPIFY_FLAG_STORE_PASSWORD' => 'geheim-wachtwoord-123', 'FAKE_SHOPIFY_ECHO_PASSWORD' => '1']);

        expect($start->successful())->toBeTrue($start->errorOutput())
            ->and($this->shopify->calls())->toContain('store-password-set')
            ->and($this->shopify->calls())->not->toContain('geheim-wachtwoord-123')
            ->and($start->output())->not->toContain('geheim-wachtwoord-123')
            ->and(file_get_contents($this->shopify->stateDirectory().'/dev.log'))->toContain('wachtwoord is ••••')->not->toContain('geheim-wachtwoord-123');

        $logs = $this->shopify->run(['shopify', 'logs', 'demo', '--lines', '5'], ['SHOPIFY_FLAG_STORE_PASSWORD' => 'geheim-wachtwoord-123']);
        expect($logs->output())->not->toContain('geheim-wachtwoord-123');
    });

    it('zet theme-editor-sync en host alleen aan als dat is ingesteld', function (): void {
        $this->shopify->run(['shopify', 'update', 'demo', '--sync', '1', '--host', '0.0.0.0'])->throw();

        $start = $this->shopify->run(['start', 'demo']);

        expect($start->successful())->toBeTrue($start->errorOutput())
            ->and($this->shopify->calls())->toContain('--host 0.0.0.0', '--theme-editor-sync --reconciliation-strategy keep-local')
            ->and($start->errorOutput())->toContain('hele netwerk');
    });

    it('toont het dev-log en de status', function (): void {
        $this->shopify->run(['start', 'demo'])->throw();

        $logs = $this->shopify->run(['shopify', 'logs', 'demo']);
        $status = json_decode($this->shopify->run(['shopify', 'status', 'demo', '--json'])->output(), true);

        expect($logs->output())->toContain('Preview your theme')
            ->and($status)->toMatchArray(['running' => true, 'url' => 'http://127.0.0.1:'.$this->shopify->port]);
    });
});

describe('thema\'s en ophalen', function (): void {
    beforeEach(function (): void {
        $this->path = $this->shopify->addProject('demo');
    });

    it('geeft de themalijst genormaliseerd terug', function (): void {
        $themes = ($this->lastLine)($this->shopify->run(['shopify', 'themes', 'demo', '--json'])->throw()->output());

        expect($themes)->toBe([
            ['id' => 100, 'name' => 'Live thema', 'role' => 'live', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=100', 'editor_url' => 'https://demo-winkel.myshopify.com/admin/themes/100/editor', 'updated_at' => null],
            ['id' => 200, 'name' => 'Oud thema', 'role' => 'unpublished', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=200', 'editor_url' => 'https://demo-winkel.myshopify.com/admin/themes/200/editor', 'updated_at' => null],
        ]);
    });

    it('haalt alleen de bestanden op die klanten in de editor aanpassen en legt ze vast in git', function (): void {
        $this->shopify->writeRemote(100, [
            'templates/index.json' => "{\"sections\":{\"klant\":\"NIEUW\"}}\n",
            'config/settings_data.json' => "{\"current\":\"nieuwe-kleur\"}\n",
            'snippets/knop.liquid' => "<button>door een ontwikkelaar op live aangepast</button>\n",
        ]);

        $pull = $this->shopify->run(['shopify', 'pull', 'demo']);

        expect($pull->successful())->toBeTrue($pull->errorOutput().$pull->output())
            ->and($pull->output())->toContain('PULL_CHANGED=2')
            ->and(file_get_contents($this->path.'/templates/index.json'))->toContain('NIEUW')
            ->and(file_get_contents($this->path.'/config/settings_data.json'))->toContain('nieuwe-kleur')
            ->and(file_get_contents($this->path.'/snippets/knop.liquid'))->toBe("<button>live</button>\n")
            ->and($this->shopify->git($this->path, ['log', '-1', '--format=%s']))->toContain('editor-wijzigingen')
            ->and($this->shopify->git($this->path, ['status', '--porcelain']))->toBe('')
            ->and($this->shopify->calls())->toContain('--theme 100', '--only config/settings_data.json --only templates/*.json --only sections/*.json')
            ->and($this->shopify->calls())->not->toContain('--live');
    });

    it('meldt dat er niets veranderd is', function (): void {
        $pull = $this->shopify->run(['shopify', 'pull', 'demo']);

        expect($pull->successful())->toBeTrue()->and($pull->output())->toContain('Al up-to-date', 'PULL_CHANGED=0');
    });

    it('weigert te overschrijven wat je lokaal nog niet vastgelegd hebt', function (): void {
        file_put_contents($this->path.'/templates/index.json', "{\"lokaal\":\"niet vastgelegd\"}\n");
        $this->shopify->writeRemote(100, ['templates/index.json' => "{\"sections\":{\"klant\":\"NIEUW\"}}\n"]);

        $pull = $this->shopify->run(['shopify', 'pull', 'demo']);

        expect($pull->failed())->toBeTrue()
            ->and($pull->errorOutput())->toContain('lokale wijzigingen', 'templates/index.json')
            ->and(file_get_contents($this->path.'/templates/index.json'))->toContain('niet vastgelegd');
    });

    it('haalt met --all het hele thema op en zet de deployed-tag door', function (): void {
        $this->shopify->writeRemote(100, ['snippets/knop.liquid' => "<button>nieuw</button>\n"]);

        $pull = $this->shopify->run(['shopify', 'pull', 'demo', '--all']);

        expect($pull->successful())->toBeTrue($pull->errorOutput())
            ->and(file_get_contents($this->path.'/snippets/knop.liquid'))->toContain('nieuw')
            ->and($this->shopify->git($this->path, ['rev-parse', 'deployed']))->toBe($this->shopify->git($this->path, ['rev-parse', 'HEAD']))
            ->and(($this->site)()['git']['pending_files'])->toBe(0);
    });

    it('telt klaarstaande wijzigingen zonder de editorbestanden van de klant', function (): void {
        file_put_contents($this->path.'/snippets/knop.liquid', "<button>nieuw</button>\n");
        file_put_contents($this->path.'/templates/index.json', "{\"klant\":\"anders\"}\n");
        $this->shopify->git($this->path, ['commit', '-qam', 'Aangepast']);

        expect(($this->site)()['git'])->toMatchArray(['pending_files' => 1, 'dirty' => 0, 'unpushed_commits' => 1]);
    });

    it('vraagt om in te loggen als de CLI dat nodig heeft', function (): void {
        $themes = $this->shopify->run(['shopify', 'themes', 'demo', '--json'], ['FAKE_SHOPIFY_LOGGED_OUT' => '1']);

        expect($themes->successful())->toBeTrue($themes->errorOutput())
            ->and($themes->output())->toContain('LOGIN_URL=https://accounts.shopify.com/', 'LOGIN_CODE=ABCD-EFGH');
    });
});

describe('thema controleren', function (): void {
    beforeEach(function (): void {
        $this->path = $this->shopify->addProject('demo');
        $this->offense = fn (string $severity, string $check = 'MissingAsset', string $message = "'assets/x.css' does not exist"): array => [
            'path' => $this->path.'/sections/x.liquid',
            'offenses' => [['check' => $check, 'severity' => $severity, 'start_row' => 11, 'message' => $message]],
        ];
    });

    it('meldt een schoon thema', function (): void {
        $check = $this->shopify->run(['shopify', 'check', 'demo']);

        expect($check->successful())->toBeTrue()->and($check->output())->toContain('Geen problemen gevonden');
    });

    it('laat fouten de actie mislukken en toont ze met bestand en regel', function (): void {
        $this->shopify->setCheckResult([($this->offense)('error'), ($this->offense)('warning', 'AssetPreload', 'Gebruik preload')]);

        $check = $this->shopify->run(['shopify', 'check', 'demo']);

        expect($check->failed())->toBeTrue()
            ->and($check->output())->toContain('1 fouten, 1 waarschuwingen, 0 tips', "✗ sections/x.liquid:12  MissingAsset: 'assets/x.css' does not exist", '! sections/x.liquid:12  AssetPreload');
    });

    it('geeft met --json de aantallen en de lijst terug', function (): void {
        $this->shopify->setCheckResult([($this->offense)('warning')]);

        $check = $this->shopify->run(['shopify', 'check', 'demo', '--json']);
        $json = json_decode(collect(explode("\n", trim($check->output())))->last(), true);

        expect($check->successful())->toBeTrue()
            ->and($json)->toMatchArray(['errors' => 0, 'warnings' => 1, 'info' => 0, 'new_errors' => 0])
            ->and($json['offenses'][0])->toMatchArray(['file' => 'sections/x.liquid', 'line' => 12, 'check' => 'MissingAsset', 'severity' => 'warning']);
    });
});

describe('veilig pushen', function (): void {
    beforeEach(function (): void {
        $this->path = $this->shopify->addProject('demo');
    });

    it('maakt een nieuw niet-gepubliceerd thema met de laatste editorbestanden van live en raakt live niet aan', function (): void {
        $this->shopify->writeRemote(100, ['templates/index.json' => "{\"sections\":{\"klant\":\"NIEUW\"}}\n"]);
        file_put_contents($this->path.'/snippets/knop.liquid', "<button>nieuwe versie</button>\n");
        $before = $this->shopify->themes();

        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview 1', '--yes']);

        expect($push->successful())->toBeTrue($push->errorOutput().$push->output())
            ->and($push->output())->toContain('Stap 6/6', 'PUSH_STORE=demo-winkel.myshopify.com', 'PUSH_THEME=Preview 1', 'THEME_ID=201', 'PREVIEW_URL=https://demo-winkel.myshopify.com/?preview_theme_id=201');

        // het nieuwe thema is niet gepubliceerd, bevat de code én de verse editorbestanden, en live is onveranderd
        expect($this->shopify->theme('Preview 1'))->toMatchArray(['id' => 201, 'role' => 'unpublished'])
            ->and($this->shopify->readRemote(201, 'snippets/knop.liquid'))->toContain('nieuwe versie')
            ->and($this->shopify->readRemote(201, 'templates/index.json'))->toContain('NIEUW')
            ->and($this->shopify->readRemote(201, 'config/settings_data.json'))->toContain('klant-live')
            ->and($this->shopify->readRemote(100, 'snippets/knop.liquid'))->toContain('live')
            ->and($this->shopify->theme('Live thema')['role'])->toBe('live')
            ->and(array_slice($this->shopify->themes(), 0, 2))->toBe($before)
            ->and($this->shopify->calls())->toContain('--unpublished --theme Preview 1');
        ($this->noLivePush)();

        // je branch en je niet-vastgelegde werk blijven zoals ze waren; er staat wel een tag met de exacte staat
        expect($this->shopify->git($this->path, ['branch', '--show-current']))->toBe('main')
            ->and($this->shopify->git($this->path, ['status', '--porcelain']))->toContain('snippets/knop.liquid')
            ->and($this->shopify->git($this->path, ['tag', '-l', 'bddeck/push-*']))->not->toBe('');

        $site = ($this->site)();
        expect($site['shopify']['last_push'])->toMatchArray(['theme_id' => '201', 'theme_name' => 'Preview 1', 'preview_url' => 'https://demo-winkel.myshopify.com/?preview_theme_id=201']);
    });

    it('legt de exacte staat van de werkmap vast in een tag, ook niet-vastgelegd werk', function (): void {
        file_put_contents($this->path.'/snippets/knop.liquid', "<button>niet vastgelegd</button>\n");
        file_put_contents($this->path.'/snippets/nieuw.liquid', "<p>nieuw bestand</p>\n");

        $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview', '--yes'])->throw();

        $tag = $this->shopify->git($this->path, ['tag', '-l', 'bddeck/push-*']);
        expect($this->shopify->git($this->path, ['show', $tag.':snippets/knop.liquid']))->toContain('niet vastgelegd')
            ->and($this->shopify->git($this->path, ['show', $tag.':snippets/nieuw.liquid']))->toContain('nieuw bestand')
            ->and($this->shopify->git($this->path, ['rev-list', '--count', 'main']))->toBe('1');
    });

    it('werkt een bestaand preview-thema bij zonder de editorbestanden van de klant daar te overschrijven', function (): void {
        $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview 1', '--yes'])->throw();
        // de klant past de preview aan in de editor, de ontwikkelaar past de code aan
        $this->shopify->writeRemote(201, ['templates/index.json' => "{\"sections\":{\"klant\":\"in preview aangepast\"}}\n"]);
        file_put_contents($this->path.'/snippets/knop.liquid', "<button>tweede versie</button>\n");
        file_put_contents($this->path.'/templates/index.json', "{\"sections\":{\"klant\":\"lokaal\"}}\n");
        $this->shopify->git($this->path, ['commit', '-qam', 'Wijziging']);

        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview 1', '--yes', '--no-pull']);

        expect($push->successful())->toBeTrue($push->errorOutput().$push->output())
            ->and($this->shopify->readRemote(201, 'snippets/knop.liquid'))->toContain('tweede versie')
            ->and($this->shopify->readRemote(201, 'templates/index.json'))->toContain('in preview aangepast')
            ->and($this->shopify->calls())->toContain('--theme 201 --ignore config/settings_data.json --ignore templates/*.json --ignore sections/*.json')
            ->and(count($this->shopify->themes()))->toBe(3);
        ($this->noLivePush)();
    });

    it('pusht nooit naar het live thema, ook niet als je de naam ervan opgeeft', function (): void {
        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Live thema', '--yes']);

        expect($push->failed())->toBeTrue()
            ->and($push->errorOutput())->toContain("'Live thema' is het live thema", 'nooit rechtstreeks naar live')
            ->and($this->shopify->calls())->not->toContain('theme push');
    });

    it('pusht niet naar een dev-thema', function (): void {
        $this->shopify->setThemes([...$this->shopify->themes(), ['id' => 300, 'name' => 'Dev', 'role' => 'development']]);

        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Dev', '--yes']);

        expect($push->failed())->toBeTrue()->and($push->errorOutput())->toContain('development-thema');
    });

    it('vraagt om bevestiging zonder --yes', function (): void {
        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview']);

        expect($push->failed())->toBeTrue()
            ->and($push->errorOutput())->toContain('Bevestiging nodig')
            ->and($this->shopify->calls())->not->toContain('theme push');
    });

    it('stopt bij nieuwe fouten in de themacontrole en onthoudt geaccepteerde fouten', function (): void {
        $error = fn (string $file, string $message): array => ['path' => $this->path.'/sections/'.$file, 'offenses' => [['check' => 'ValidSchema', 'severity' => 'error', 'start_row' => 0, 'message' => $message]]];
        $this->shopify->setCheckResult([$error('a.liquid', 'Kapot schema')]);

        $blocked = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'P', '--yes']);
        expect($blocked->failed())->toBeTrue()
            ->and($blocked->errorOutput())->toContain('1 nieuwe fout(en)', '--allow-check-errors')
            ->and($this->shopify->calls())->not->toContain('theme push');

        $accepted = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'P', '--yes', '--allow-check-errors']);
        expect($accepted->successful())->toBeTrue($accepted->errorOutput());

        // dezelfde fout blokkeert niet meer, een nieuwe wel
        $again = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'P', '--yes']);
        expect($again->successful())->toBeTrue($again->errorOutput())->and($again->output())->toContain('al eerder en zijn geaccepteerd');

        $this->shopify->setCheckResult([$error('a.liquid', 'Kapot schema'), $error('b.liquid', 'Nog een fout')]);
        $worse = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'P', '--yes']);
        expect($worse->failed())->toBeTrue()->and($worse->errorOutput())->toContain('1 nieuwe fout(en)');
    });

    it('stopt als het ophalen van de editorbestanden over lokaal werk heen zou gaan', function (): void {
        file_put_contents($this->path.'/templates/index.json', "{\"lokaal\":true}\n");

        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Nieuw', '--yes']);

        expect($push->failed())->toBeTrue()->and($push->errorOutput())->toContain('lokale wijzigingen')
            ->and($this->shopify->calls())->not->toContain('theme push');
    });

    it('meldt een mislukte push zonder iets als gelukt te laten lijken', function (): void {
        $push = $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'P', '--yes'], ['FAKE_SHOPIFY_FORBIDDEN' => '1']);

        expect($push->failed())->toBeTrue()->and($push->errorOutput())->toContain("Geen toegang tot de thema's");
        expect(is_file($this->shopify->stateDirectory().'/last-push'))->toBeFalse();
    });
});

describe('bewust publiceren', function (): void {
    beforeEach(function (): void {
        $this->path = $this->shopify->addProject('demo');
        $this->shopify->run(['shopify', 'push', 'demo', '--theme', 'Preview 1', '--yes'])->throw();
        file_put_contents($this->path.'/snippets/knop.liquid', "<button>nieuw</button>\n");
    });

    it('maakt eerst een back-up en een tag en zet dan het gekozen thema live', function (): void {
        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files']);

        expect($publish->successful())->toBeTrue($publish->errorOutput().$publish->output())
            ->and($publish->output())->toContain('PUBLISH_STORE=demo-winkel.myshopify.com', 'PUBLISH_CURRENT=Live thema (100)', 'PUBLISH_NEW=Preview 1 (201)', 'Stap 6/6', "'Preview 1' is nu live", 'BACKUP=');

        expect($this->shopify->theme('Preview 1')['role'])->toBe('live')
            ->and($this->shopify->theme('Live thema')['role'])->toBe('unpublished');

        $backup = $this->shopify->root.'/wp-sites/.wpopen-backups/demo/'.collect(scandir($this->shopify->root.'/wp-sites/.wpopen-backups/demo'))->first(fn (string $id): bool => str_ends_with($id, '-publish'));
        expect(file_get_contents($backup.'/live-theme/snippets/knop.liquid'))->toContain('live')
            ->and(file_get_contents($backup.'/meta'))->toContain('previous_live_id=100', 'new_theme_id=201', 'git_tag=bddeck/publish-')
            ->and($this->shopify->git($this->path, ['tag', '-l', 'bddeck/publish-*']))->not->toBe('');

        $site = ($this->site)();
        expect($site['shopify']['last_publish'])->toMatchArray(['new_id' => '201', 'previous_id' => '100', 'previous_name' => 'Live thema'])
            ->and($site['backups'])->toBe(1);
        ($this->noLivePush)();
    });

    it('zet na het publiceren alleen een tag op de gepubliceerde staat', function (): void {
        $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files'])->throw();

        $deployed = $this->shopify->git($this->path, ['rev-parse', 'deployed']);
        expect($this->shopify->git($this->path, ['show', 'deployed:snippets/knop.liquid']))->toContain('nieuw')
            ->and($deployed)->not->toBe($this->shopify->git($this->path, ['rev-parse', 'HEAD']))
            ->and($this->shopify->git($this->path, ['branch', '--show-current']))->toBe('main');
    });

    it('waarschuwt als de klant sinds de preview iets in de editor heeft aangepast en publiceert dan niet', function (): void {
        $this->shopify->writeRemote(100, ['config/settings_data.json' => "{\"current\":\"klant heeft kleur gewijzigd\"}\n"]);

        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes']);

        expect($publish->failed())->toBeTrue()
            ->and($publish->errorOutput())->toContain('sinds de preview iets aangepast', 'config/settings_data.json', '--sync-editor-files', '--keep-editor-files', 'Er is niets gepubliceerd')
            ->and($publish->output())->toContain('PUBLISH_DRIFT=1')
            ->and($this->shopify->theme('Preview 1')['role'])->toBe('unpublished')
            ->and($this->shopify->theme('Live thema')['role'])->toBe('live')
            ->and($this->shopify->calls())->not->toContain('theme publish');
    });

    it('neemt met --sync-editor-files de live-versie over, zodat er niets van de klant verloren gaat', function (): void {
        $this->shopify->writeRemote(100, ['config/settings_data.json' => "{\"current\":\"klant heeft kleur gewijzigd\"}\n", 'templates/nieuw.json' => "{\"nieuw\":true}\n"]);

        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files']);

        expect($publish->successful())->toBeTrue($publish->errorOutput().$publish->output())
            ->and($this->shopify->readRemote(201, 'config/settings_data.json'))->toContain('klant heeft kleur gewijzigd')
            ->and($this->shopify->readRemote(201, 'templates/nieuw.json'))->toContain('nieuw')
            ->and($this->shopify->theme('Preview 1')['role'])->toBe('live')
            // en ook lokaal staat nu wat de klant deed, vastgelegd in git
            ->and(file_get_contents($this->path.'/config/settings_data.json'))->toContain('klant heeft kleur gewijzigd')
            ->and($this->shopify->git($this->path, ['log', '--format=%s']))->toContain('editor-wijzigingen');
    });

    it('publiceert met --keep-editor-files bewust de preview-versie en waarschuwt dat de wijziging verloren gaat', function (): void {
        $this->shopify->writeRemote(100, ['config/settings_data.json' => "{\"current\":\"klant heeft kleur gewijzigd\"}\n"]);

        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--keep-editor-files']);

        expect($publish->successful())->toBeTrue($publish->errorOutput())
            ->and($publish->errorOutput())->toContain('die wijzigingen van de klant gaan verloren', 'back-up')
            ->and($this->shopify->readRemote(201, 'config/settings_data.json'))->toContain('klant-live');
    });

    it('weigert het live thema, een onbekend thema en een thema dat geen preview is', function (): void {
        $this->shopify->setThemes([...$this->shopify->themes(), ['id' => 300, 'name' => 'Dev', 'role' => 'development']]);

        $results = collect(['Live thema', 'Bestaat niet', 'Dev'])->map(fn (string $theme) => $this->shopify->run(['shopify', 'publish', 'demo', '--theme', $theme, '--yes', '--sync-editor-files']));

        expect($results[0]->errorOutput())->toContain('al het live thema')
            ->and($results[1]->errorOutput())->toContain('bestaat niet in')
            ->and($results[2]->errorOutput())->toContain('alleen niet-gepubliceerde thema')
            ->and($this->shopify->calls())->not->toContain('theme publish');
    });

    it('vraagt om bevestiging en toont winkel en thema\'s', function (): void {
        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1']);

        expect($publish->failed())->toBeTrue()
            ->and($publish->errorOutput())->toContain('Bevestiging nodig', 'Preview 1 live zetten in demo-winkel.myshopify.com (nu live: Live thema)')
            ->and($this->shopify->calls())->not->toContain('theme publish');
    });

    it('publiceert niets als de back-up van live mislukt', function (): void {
        $this->shopify->stub('shopify', str_replace('"theme pull")', '"theme pull")'."\n      [ -n \"\${FAKE_SHOPIFY_PULL_FAIL:-}\" ] && { echo 'Error: pull failed' >&2; exit 1; }", (string) file_get_contents($this->shopify->bin.'/shopify')));

        $publish = $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files'], ['FAKE_SHOPIFY_PULL_FAIL' => '1']);

        expect($publish->failed())->toBeTrue()
            ->and($publish->errorOutput())->toContain('Back-up van het live thema mislukt; er is niets gepubliceerd')
            ->and($this->shopify->theme('Live thema')['role'])->toBe('live')
            ->and($this->shopify->calls())->not->toContain('theme publish');
    });

    it('draait een publicatie terug naar het vorige live thema', function (): void {
        $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files'])->throw();

        $rollback = $this->shopify->run(['shopify', 'rollback', 'demo', '--yes']);

        expect($rollback->successful())->toBeTrue($rollback->errorOutput().$rollback->output())
            ->and($rollback->output())->toContain('ROLLBACK_TO=Live thema (100)', "'Live thema' is weer live")
            ->and($this->shopify->theme('Live thema')['role'])->toBe('live')
            ->and($this->shopify->theme('Preview 1')['role'])->toBe('unpublished')
            ->and(($this->site)()['shopify']['last_publish']['rolled_back_at'])->not->toBeNull();

        $again = $this->shopify->run(['shopify', 'rollback', 'demo', '--yes']);
        expect($again->failed())->toBeTrue()->and($again->errorOutput())->toContain('geen publicatie om terug te draaien');
    });

    it('draait niet terug als het live thema sindsdien door iets anders is veranderd', function (): void {
        $this->shopify->run(['shopify', 'publish', 'demo', '--theme', 'Preview 1', '--yes', '--sync-editor-files'])->throw();
        $this->shopify->setThemes(collect($this->shopify->themes())->map(fn (array $theme): array => [...$theme, 'role' => $theme['id'] === 200 ? 'live' : ($theme['role'] === 'live' ? 'unpublished' : $theme['role'])])->all());

        $rollback = $this->shopify->run(['shopify', 'rollback', 'demo', '--yes']);

        expect($rollback->failed())->toBeTrue()->and($rollback->errorOutput())->toContain('sinds de laatste publicatie veranderd');
    });
});

describe('verbindingstest', function (): void {
    beforeEach(function (): void {
        $this->shopify->addProject('demo');
    });

    it('controleert CLI, themamap, winkel, dev server en live adres', function (): void {
        $checks = collect(json_decode($this->shopify->run(['test', 'demo', '--json'])->throw()->output(), true))->keyBy('key');

        expect($checks['cli']['status'])->toBe('ok')
            ->and($checks['project']['status'])->toBe('ok')
            ->and($checks['store'])->toMatchArray(['status' => 'ok'])
            ->and($checks['dev']['status'])->toBe('skip')
            ->and($checks['http']['status'])->toBe('ok');
    });

    it('meldt dat je niet bent ingelogd zonder te blijven wachten', function (): void {
        $checks = collect(json_decode($this->shopify->run(['test', 'demo', '--json'], ['FAKE_SHOPIFY_LOGGED_OUT' => '1'])->throw()->output(), true))->keyBy('key');

        expect($checks['store']['status'])->toBe('warn')->and($checks['store']['detail'])->toContain('niet ingelogd');
    });

    it('meldt een ontbrekende Shopify CLI', function (): void {
        unlink($this->shopify->bin.'/shopify');

        $checks = collect(json_decode($this->shopify->run(['test', 'demo', '--json'])->throw()->output(), true))->keyBy('key');

        expect($checks['cli']['status'])->toBe('fail')->and($checks['cli']['detail'])->toContain('npm install -g @shopify/cli');
    });

    it('meldt een ontbrekende of onjuiste themamap', function (): void {
        $this->shopify->run(['shopify', 'update', 'demo', '--path', $this->shopify->projects.'/weg'])->throw();

        $checks = collect(json_decode($this->shopify->run(['test', 'demo', '--json'])->throw()->output(), true))->keyBy('key');

        expect($checks['project']['status'])->toBe('fail')->and($checks['project']['detail'])->toContain('bestaat niet');
    });
});

describe('Shopify CLI zoeken', function (): void {
    it('vindt een CLI in een nvm-map, ook als de app zonder die paden start', function (): void {
        $this->shopify->addProject('demo');
        $nvm = $this->shopify->home.'/.nvm/versions/node/v24.0.0/bin';
        mkdir($nvm, 0755, true);
        rename($this->shopify->bin.'/shopify', $nvm.'/shopify');

        $themes = $this->shopify->run(['shopify', 'themes', 'demo', '--json']);

        expect($themes->successful())->toBeTrue($themes->errorOutput())->and(($this->lastLine)($themes->output()))->toHaveCount(2);
    });

    it('legt uit hoe je de CLI installeert als hij nergens staat', function (): void {
        $this->shopify->addProject('demo');
        unlink($this->shopify->bin.'/shopify');

        $themes = $this->shopify->run(['shopify', 'themes', 'demo']);

        expect($themes->failed())->toBeTrue()->and($themes->errorOutput())->toContain('npm install -g @shopify/cli');
    });
});
