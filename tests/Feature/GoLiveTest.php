<?php

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\GoLive;
use App\Livewire\Sites\Show;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeServer;

/**
 * Een gezonde live-site op demo.test; per test wordt één ding kapot gemaakt.
 *
 * @return array<string, array<string, mixed>>
 */
function healthyWeb(): array
{
    return [
        'https://demo.test/' => ['body' => '<html><a href="https://demo.test/contact">Contact</a></html>'],
        'https://demo.test/robots.txt' => ['body' => "User-agent: *\nDisallow: /wp-admin/\n", 'type' => 'text/plain'],
        'https://demo.test/wp-content/debug.log' => ['status' => 404],
        'http://demo.test/' => ['redirect' => 'https://demo.test/'],
        'https://www.demo.test/' => ['redirect' => 'https://demo.test/'],
        'https://demo.test/wp-sitemap.xml' => ['body' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>', 'type' => 'application/xml'],
    ];
}

/**
 * @param  array<string, string>  $overrides
 */
function liveFacts(array $overrides = []): string
{
    $facts = array_replace([
        'siteurl' => 'https://demo.test',
        'blog_public' => '1',
        'wp_debug' => '0',
        'debug_display' => '1',
        'mail_plugin' => 'WP Mail SMTP',
    ], $overrides);

    return collect($facts)->map(fn (string $value, string $key): string => $key.'='.$value)->implode("\n");
}

describe('wpopen golive', function (): void {
    beforeEach(function (): void {
        $this->server = FakeServer::create();

        /**
         * @param  array<string, array<string, mixed>>  $web
         * @param  array<string, string>  $environment
         * @param  list<string>  $extra
         * @return Collection<string, array{key: string, status: string, label: string, detail: string}>
         */
        $this->checklist = function (array $web = [], array $environment = [], array $extra = [], string $site = 'demo'): Collection {
            $this->server->fakeWeb(array_replace(healthyWeb(), $web));
            $result = $this->server->run(['golive', $site, '--json', ...$extra], ['FAKE_GOLIVE' => liveFacts(), ...$environment]);

            expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

            $lines = array_filter(explode("\n", trim($result->output())));

            return collect(json_decode((string) end($lines), true))->keyBy('key');
        };
    });

    afterEach(function (): void {
        $this->server->destroy();
    });

    it('keurt een goed ingerichte site helemaal goed, inclusief een echte testmail', function (): void {
        $checks = ($this->checklist)(environment: ['FAKE_GOLIVE' => liveFacts()."\nmail=ok"], extra: ['--mail-to', 'wesley@van-ons.nl']);

        expect($checks->keys()->all())->toBe(['search', 'debug', 'mail', 'local-urls', 'https', 'www', 'sitemap'])
            ->and($checks->pluck('status')->unique()->all())->toBe(['ok'])
            ->and($checks['mail']['detail'])->toContain('wesley@van-ons.nl', 'WP Mail SMTP')
            ->and($checks['sitemap']['detail'])->toContain('https://demo.test/wp-sitemap.xml')
            ->and($this->server->calls())->toContain("wp_mail('wesley@van-ons.nl'", 'search-replace ddev.site wpo-golive.invalid --dry-run');
    });

    it('vindt alle klassieke fouten na een verhuizing', function (): void {
        $checks = ($this->checklist)([
            'https://demo.test/' => ['body' => '<img src="https://demo.ddev.site/wp-content/uploads/logo.png"><a href="https://demo.ddev.site/contact">'],
            'https://demo.test/robots.txt' => ['body' => "User-agent: *\nDisallow: /\n", 'type' => 'text/plain'],
            'https://demo.test/wp-content/debug.log' => ['body' => 'PHP Notice', 'type' => 'text/plain'],
            'http://demo.test/' => [],
            'https://www.demo.test/' => [],
            'https://demo.test/wp-sitemap.xml' => ['status' => 404],
        ], [
            'FAKE_GOLIVE' => liveFacts(['blog_public' => '0', 'wp_debug' => '1', 'mail_plugin' => '']),
            'FAKE_LOCAL_URLS' => '12',
        ]);

        expect($checks->map->status->all())->toBe([
            'search' => 'fail', 'debug' => 'fail', 'mail' => 'fail', 'local-urls' => 'fail',
            'https' => 'warn', 'www' => 'fail', 'sitemap' => 'fail',
        ])
            ->and($checks['search']['detail'])->toContain('Zoekmachines ontmoedigen', 'robots.txt')
            ->and($checks['debug']['detail'])->toContain('WP_DEBUG staat aan', 'debug.log')
            ->and($checks['mail']['detail'])->toContain('geen SMTP-plugin')
            ->and($checks['local-urls']['detail'])->toContain('12× een lokaal adres', '2× .ddev.site in de homepage')
            ->and($checks['https']['detail'])->toContain('stuurt niet door naar https://')
            ->and($checks['www']['detail'])->toContain('doorverwijzing in van www.demo.test naar demo.test')
            ->and($checks['sitemap']['detail'])->toContain('SEO-plugin', 'zoekmachines ontmoedigen');
    });

    it('waarschuwt waar het niet per se fout is', function (): void {
        $checks = ($this->checklist)(['https://www.demo.test/' => ['error' => 6]]);

        expect($checks['mail'])->toMatchArray(['status' => 'warn'])
            ->and($checks['mail']['detail'])->toContain('Stuur een testmail')
            ->and($checks['www'])->toMatchArray(['status' => 'warn'])
            ->and($checks['www']['detail'])->toContain('www.demo.test heeft geen DNS-record');

        $text = $this->server->run(['golive', 'demo'], ['FAKE_GOLIVE' => liveFacts()]);
        expect($text->output())->toContain('! Mail versturen — WP Mail SMTP is actief', '✓ HTTPS werkt');
    });

    it('meldt een ongeldig certificaat en een mislukte testmail met de foutmelding', function (): void {
        $checks = ($this->checklist)(
            ['https://demo.test/' => ['error' => 60, 'message' => 'SSL certificate problem: certificate has expired']],
            ['FAKE_GOLIVE' => liveFacts()."\nmail=fail\nmail_error=SMTP connect() failed."],
            ['--mail-to', 'test@klant.nl'],
        );

        expect($checks['https'])->toMatchArray(['status' => 'fail'])
            ->and($checks['https']['detail'])->toContain('geen geldig certificaat voor demo.test', 'certificate has expired')
            ->and($checks['mail'])->toMatchArray(['status' => 'fail'])
            ->and($checks['mail']['detail'])->toContain('test@klant.nl mislukt via WP Mail SMTP: SMTP connect() failed.');
    });

    it('zoekt na het omzetten van het domein ook naar het oude adres', function (): void {
        $backup = $this->server->sites.'/.wpopen-backups/demo/20261016-120000-domain';
        mkdir($backup, recursive: true);
        file_put_contents($backup.'/meta', "kind=domain\nfrom=tijdelijk.example.test\nto=demo.test\n");

        $checks = ($this->checklist)(environment: ['FAKE_OLD_URLS' => '3']);

        expect($checks['local-urls'])->toMatchArray(['status' => 'fail'])
            ->and($checks['local-urls']['detail'])->toContain('6× het oude adres tijdelijk.example.test in de database')
            ->and($this->server->calls())->toContain('search-replace //tijdelijk.example.test wpo-golive.invalid', 'search-replace \/\/tijdelijk.example.test wpo-golive.invalid');
    });

    it('zet zoekmachines aan, WP_DEBUG uit en lokale adressen om, en controleert daarna opnieuw', function (string $fix, string $expected): void {
        $checks = ($this->checklist)(extra: ['--fix', $fix]);

        expect($this->server->calls())->toContain($expected)
            ->and($checks)->toHaveCount(7);
    })->with([
        'zoekmachines' => ['search', "update_option('blog_public', '1')"],
        'debug' => ['debug', 'config set WP_DEBUG false --raw --type=constant'],
        'lokale adressen' => ['local-urls', 'search-replace //demo.ddev.site //demo.test --all-tables --skip-columns=guid'],
    ]);

    it('zet bij lokale adressen ook JSON-adressen en het oude domein om, met eerst een back-up', function (): void {
        $backup = $this->server->sites.'/.wpopen-backups/demo/20261016-120000-domain';
        mkdir($backup, recursive: true);
        file_put_contents($backup.'/meta', "kind=domain\nfrom=tijdelijk.example.test\nto=demo.test\n");

        ($this->checklist)(extra: ['--fix', 'local-urls']);

        expect($this->server->calls())->toContain(
            'wp db export',
            'search-replace \/\/demo.ddev.site \/\/demo.test',
            'search-replace //tijdelijk.example.test //demo.test',
            'search-replace \/\/tijdelijk.example.test \/\/demo.test',
        )->and(collect($this->server->backups())->last())->toEndWith('-go-live');
    });

    it('werkt bij alleen SFTP via een tijdelijk PHP-bestand', function (): void {
        file_put_contents($this->server->home.'/.config/wpsites/sites', "klant|wesley@demo.test||site/public_html/wp-content|sftp|other|https://demo.test\n", FILE_APPEND);

        $checks = ($this->checklist)(environment: ['FAKE_LIVE_PHP' => liveFacts(['blog_public' => '0'])."\nlocal_urls=3"], extra: ['--fix', 'search'], site: 'klant');

        expect($checks['local-urls']['detail'])->toContain('3× een lokaal adres')
            ->and($this->server->lftpUploads())->toContain("update_option('blog_public', '1')", 'wpo-golive', 'ddev.site')
            ->and($this->server->calls())->not->toContain('wp --path');
    });

    it('weigert reparaties die bij SFTP niet kunnen', function (): void {
        file_put_contents($this->server->home.'/.config/wpsites/sites', "klant|wesley@demo.test||site/public_html/wp-content|sftp|other|https://demo.test\n", FILE_APPEND);
        $this->server->fakeWeb(healthyWeb());

        $result = $this->server->run(['golive', 'klant', '--fix', 'debug']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('alleen bij SSH-sites');
    });

    it('controleert bij Laravel de .env en slaat WordPress-onderdelen over', function (): void {
        $site = $this->server->laravelSite();
        file_put_contents($site['remote'].'/.env', "APP_ENV=production\nAPP_DEBUG=true\nAPP_URL=https://shop.test\n");

        $checks = ($this->checklist)([
            'https://shop.test/' => [],
            'http://shop.test/' => ['redirect' => 'https://shop.test/'],
            'https://www.shop.test/' => ['redirect' => 'https://shop.test/'],
        ], site: 'shop');

        expect($checks->map->status->all())->toBe([
            'search' => 'skip', 'debug' => 'fail', 'mail' => 'skip', 'local-urls' => 'skip',
            'https' => 'ok', 'www' => 'ok', 'sitemap' => 'warn',
        ])->and($checks['debug']['detail'])->toContain('APP_DEBUG staat aan');
    });

    it('weigert lokale sites en ongeldige invoer', function (array $arguments, string $message): void {
        file_put_contents($this->server->home.'/.config/wpsites/sites', "nieuw||||local||\n", FILE_APPEND);

        $result = $this->server->run(['golive', ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message);
    })->with([
        'lokale site' => [['nieuw'], 'lokale site zonder live-server'],
        'e-mailadres' => [['demo', '--mail-to', "x'@klant.nl"], 'Ongeldig e-mailadres'],
        'onderdeel' => [['demo', '--fix', 'alles'], 'Onbekend onderdeel'],
    ]);
});

/**
 * @return list<array{key: string, status: string, label: string, detail: string}>
 */
function goLiveChecks(string $mail = 'ok', string $sitemap = 'ok'): array
{
    return [
        ['key' => 'search', 'status' => 'fail', 'label' => 'Zichtbaar voor zoekmachines', 'detail' => "'Zoekmachines ontmoedigen' staat aan (Instellingen → Lezen)"],
        ['key' => 'debug', 'status' => 'fail', 'label' => 'Debugmodus uit', 'detail' => 'WP_DEBUG staat aan op live'],
        ['key' => 'mail', 'status' => $mail, 'label' => 'Mail versturen', 'detail' => 'WP Mail SMTP is actief'],
        ['key' => 'local-urls', 'status' => 'fail', 'label' => 'Geen lokale adressen', 'detail' => '4× een lokaal adres (.ddev.site) in de database'],
        ['key' => 'https', 'status' => 'ok', 'label' => 'HTTPS werkt', 'detail' => 'geldig certificaat'],
        ['key' => 'www', 'status' => 'fail', 'label' => 'www en zonder www', 'detail' => 'www.klant.nl toont de site zonder door te sturen'],
        ['key' => 'sitemap', 'status' => $sitemap, 'label' => 'Sitemap', 'detail' => 'gevonden'],
    ];
}

describe('go-live-commando', function (): void {
    it('bouwt de argumenten op en controleert de invoer', function (): void {
        $site = Site::factory()->create(['name' => 'klant']);

        expect(WpOpenCommand::goLive($site)->arguments)->toBe(['golive', 'klant', '--json'])
            ->and(WpOpenCommand::goLive($site, mailTo: 'wesley@van-ons.nl', fix: 'local-urls')->arguments)
            ->toBe(['golive', 'klant', '--json', '--mail-to', 'wesley@van-ons.nl', '--fix', 'local-urls'])
            ->and(WpOpenCommand::goLive($site)->action)->toBe(WpOpenAction::GoLive);
    });

    it('weigert wat niet kan', function (Closure $site, ?string $mailTo, ?string $fix): void {
        WpOpenCommand::goLive($site(), $mailTo, $fix);
    })->with([
        'lokale site' => [fn (): Site => Site::factory()->localOnly()->create(), null, null],
        'ongeldig adres' => [fn (): Site => Site::factory()->create(), "x'@klant.nl", null],
        'onbekend onderdeel' => [fn (): Site => Site::factory()->create(), null, 'alles'],
        'debug zonder SSH' => [fn (): Site => Site::factory()->sftp()->create(), null, 'debug'],
        'Laravel repareren' => [fn (): Site => Site::factory()->laravel()->create(), null, 'search'],
    ])->throws(InvalidArgumentException::class);
});

describe('na afloop', function (): void {
    beforeEach(function (): void {
        $this->notifier = Mockery::mock(DesktopNotifier::class)->shouldIgnoreMissing();
        $this->app->instance(DesktopNotifier::class, $this->notifier);
    });

    it('bewaart de uitkomst van de checklist bij de site', function (): void {
        $site = Site::factory()->create();
        Process::fake(['*' => Process::result(json_encode(goLiveChecks()))]);

        app(CommandRunner::class)->queue(WpOpenCommand::goLive($site));

        $site->refresh();
        expect($site->go_live)->toBe(goLiveChecks())
            ->and($site->go_live_checked_at)->not->toBeNull()
            ->and($site->goLiveIssues())->toBe(4)
            ->and($site->goLivePassed())->toBe(3)
            ->and($site->goLiveApplicable())->toBe(7);
        $this->notifier->shouldNotHaveReceived('goLiveIssues');
    });

    it('draait de checklist vanzelf na verhuizen en domein omzetten en meldt alleen problemen', function (WpOpenCommand $command, array $checks, bool $notified): void {
        Process::fake([
            '*golive*' => Process::result(json_encode($checks)),
            '*info*' => Process::result(json_encode(Site::query()->first()->snapshot)),
            '*list*' => Process::result(json_encode([Site::query()->first()->snapshot])),
            '*' => Process::result("✓ Klaar\n"),
        ]);

        app(CommandRunner::class)->queue($command);

        $site = Site::query()->first();
        $goLive = $site->runs()->where('action', WpOpenAction::GoLive)->sole();

        expect($goLive->arguments)->toBe(['golive', $site->name, '--json'])
            ->and($goLive->status)->toBe(RunStatus::Succeeded)
            ->and($site->go_live)->toBe($checks);

        $notified
            ? $this->notifier->shouldHaveReceived('goLiveIssues')->once()->withArgs(fn (Site $notifiedSite, int $count): bool => $notifiedSite->is($site) && $count === 4)
            : $this->notifier->shouldNotHaveReceived('goLiveIssues');
    })->with([
        'verhuizen met problemen' => [fn (): WpOpenCommand => WpOpenCommand::migrate(Site::factory()->create(['name' => 'klant']), 'u@nieuw.test', 'https://klant.nl'), goLiveChecks(), true],
        'domein zonder problemen' => [fn (): WpOpenCommand => WpOpenCommand::changeDomain(Site::factory()->create(['name' => 'klant']), 'https://klant.nl'), [['key' => 'https', 'status' => 'ok', 'label' => 'HTTPS werkt', 'detail' => 'ok']], false],
    ]);

    it('start geen checklist na een mislukte verhuizing', function (): void {
        $site = Site::factory()->create();
        Process::fake(['*' => Process::result(errorOutput: "✗ Inloggen mislukt\n", exitCode: 1)]);

        app(CommandRunner::class)->queue(WpOpenCommand::changeDomain($site, 'https://klant.nl'));

        expect($site->runs()->where('action', WpOpenAction::GoLive)->exists())->toBeFalse();
    });
});

describe('go-live-paneel', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Process::fake();
        $this->actingAs(User::factory()->owner()->create());
    });

    it('staat alleen bij sites die live staan', function (): void {
        Livewire::test(Show::class, ['site' => Site::factory()->create()])->assertSeeLivewire(GoLive::class);
        Livewire::test(Show::class, ['site' => Site::factory()->localOnly()->create()])->assertDontSeeLivewire(GoLive::class);
    });

    it('legt uit wat er gecontroleerd wordt en start de controle', function (): void {
        $site = Site::factory()->create(['name' => 'klant']);

        Livewire::test(GoLive::class, ['site' => $site])
            ->assertSee(__('Nog niet gecontroleerd'))
            ->call('check');

        Queue::assertPushedOn('quick', RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['golive', 'klant', '--json'] && $job->notify);
    });

    it('toont de punten met per punt wat je kunt doen', function (): void {
        $site = Site::factory()->create(['name' => 'klant', 'go_live' => goLiveChecks(mail: 'warn', sitemap: 'fail'), 'go_live_checked_at' => now()]);

        Livewire::test(GoLive::class, ['site' => $site])
            ->assertSee(['Gecontroleerd', '1 van 7 in orde'])
            ->assertSee(['Zichtbaar voor zoekmachines', 'Aanzetten', 'Uitzetten', 'Omzetten', 'Testmail sturen'])
            ->assertSee('Stel bij je hosting een doorverwijzing in van www.klant.nl naar klant.nl.')
            ->assertSee('Zet de sitemap aan in je SEO-plugin')
            ->assertDontSee('Net verhuisd');
    });

    it('laat bij alleen SFTP alleen zoekmachines repareren', function (): void {
        $site = Site::factory()->sftp()->create(['go_live' => goLiveChecks(), 'go_live_checked_at' => now()]);

        Livewire::test(GoLive::class, ['site' => $site])
            ->assertSee('Aanzetten')
            ->assertDontSee('Uitzetten')
            ->assertSee('Zet WP_DEBUG op false in de wp-config.php op live.')
            ->call('confirmFix', 'debug')
            ->assertSet('fixing', null);
    });

    it('zet na bevestiging een onderdeel recht', function (string $fix): void {
        $site = Site::factory()->create(['name' => 'klant', 'go_live' => goLiveChecks(), 'go_live_checked_at' => now()]);

        Livewire::test(GoLive::class, ['site' => $site])
            ->call('confirmFix', $fix)
            ->assertSet('fixing', $fix)
            ->assertDispatched('open-modal', name: 'go-live-fix')
            ->call('fix')
            ->assertSet('fixing', null);

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['golive', 'klant', '--json', '--fix', $fix]);
    })->with(['search', 'debug', 'local-urls']);

    it('stuurt een testmail en onthoudt het adres', function (): void {
        Setting::query()->create(['key' => 'go_live_mail_to', 'value' => 'oud@van-ons.nl']);
        $site = Site::factory()->create(['name' => 'klant', 'go_live' => goLiveChecks(), 'go_live_checked_at' => now()]);

        Livewire::test(GoLive::class, ['site' => $site])
            ->assertSet('mailTo', 'oud@van-ons.nl')
            ->set('mailTo', 'geen-adres')
            ->call('sendTestMail')
            ->assertHasErrors('mailTo')
            ->set('mailTo', ' wesley@van-ons.nl ')
            ->call('sendTestMail')
            ->assertHasNoErrors();

        expect(app(AppSettings::class)->goLiveMailTo())->toBe('wesley@van-ons.nl');
        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['golive', 'klant', '--json', '--mail-to', 'wesley@van-ons.nl']);
    });

    it('springt eruit na een verhuizing en volgt een lopende controle', function (): void {
        $site = Site::factory()->create(['go_live' => goLiveChecks(), 'go_live_checked_at' => now()]);
        $site->runs()->create(['site_name' => $site->name, 'action' => WpOpenAction::Migrate, 'label' => 'Verhuizen', 'arguments' => [], 'status' => RunStatus::Succeeded, 'finished_at' => now()->subDay()]);
        $run = $site->runs()->create(['site_name' => $site->name, 'action' => WpOpenAction::GoLive, 'label' => 'Go-live-checklist', 'arguments' => [], 'status' => RunStatus::Running]);

        $component = Livewire::test(GoLive::class, ['site' => $site])
            ->assertSee(['Net verhuisd', 'Bezig met controleren', 'ring-warning/40'])
            ->assertSeeHtml('wire:poll.2s="poll"');

        $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
        $site->update(['go_live' => [['key' => 'https', 'status' => 'ok', 'label' => 'HTTPS werkt', 'detail' => 'ok']]]);

        $component->call('poll')
            ->assertDontSee('Bezig met controleren')
            ->assertSee('1 van 1 in orde');
    });
});
