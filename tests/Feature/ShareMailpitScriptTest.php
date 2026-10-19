<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeServer;

beforeEach(function (): void {
    $this->server = FakeServer::create();
    $this->server->buildLocalSite();
    $this->state = $this->server->sites.'/demo/.wpopen-share';

    // ddev share met cloudflared nabootsen: eerst wat ruis (ook het api-adres uit foutmeldingen), dan de link
    $this->server->extendDdev(<<<'BASH'
          share*)
            echo "ddev $*" >> "$FAKE_LOG"
            [ -z "${FAKE_SHARE_FAILS:-}" ] || { echo "Error: tunnel kon niet starten" >&2; exit 1; }
            echo "cloudflared=$(command -v cloudflared)" >> "$FAKE_LOG"
            echo "Starting cloudflared tunnel..." >&2
            echo "failed to request https://api.trycloudflare.com/tunnel" >&2
            echo "https://proef-123.trycloudflare.com"
            sleep 300; exit 0;;  # geen exec: net als de echte ddev blijft "share" in de opdrachtregel staan
          *wp_mail*) echo "ddev $*" >> "$FAKE_LOG"; echo "wpo-mail-ok";;
        BASH);
    $this->server->fakeCommand('cloudflared', "#!/usr/bin/env bash\necho 'cloudflared version 2026.9.0'");
});

afterEach(function (): void {
    // nooit tunnels of bewakers laten draaien na een test
    $this->server->run(['share', 'demo', '--stop']);
    $this->server->destroy();
});

/**
 * @return array<string, string>
 */
function shareState(string $file): array
{
    return collect(file($file, FILE_IGNORE_NEW_LINES) ?: [])
        ->mapWithKeys(fn (string $line): array => [Str::before($line, '=') => Str::after($line, '=')])
        ->all();
}

function processAlive(int $pid): bool
{
    return Process::run(['kill', '-0', (string) $pid])->successful();
}

/**
 * Draait de mu-plugin met een minimale WordPress-nabootsing en geeft de uitvoer terug.
 *
 * @param  array<string, string>  $server
 * @param  array<string, string>  $query
 */
function requestThroughPlugin(string $plugin, string $wpContent, array $server, array $query = []): string
{
    $prelude = <<<'PHP'
        define('WP_CONTENT_DIR', $argv[1]);
        $_SERVER = array_merge($_SERVER, json_decode($argv[2], true));
        $_GET = json_decode($argv[3], true);
        $GLOBALS['hooks'] = [];
        function add_filter($hook, $callback) { $GLOBALS['hooks'][$hook][] = $callback; return true; }
        function add_action($hook, $callback) { return add_filter($hook, $callback); }
        function apply_filters($hook, $value) { foreach ($GLOBALS['hooks'][$hook] ?? [] as $callback) { $value = $callback($value); } return $value; }
        function __return_zero() { return 0; }
        function __return_false() { return false; }
        function __return_true() { return true; }
        require $argv[4];
        echo 'home=', apply_filters('option_home', 'https://demo.ddev.site'), "\n";
        echo 'user=', apply_filters('determine_current_user', 1), "\n";
        echo 'mailers=', implode(',', apply_filters('option_active_plugins', ['forminator/forminator.php', 'wp-mail-smtp/wp_mail_smtp.php', 'fluent-smtp/fluent-smtp.php'])), "\n";
        echo '<a href="https://demo.ddev.site/contact/">x</a> {"u":"https:\/\/demo.ddev.site\/x"}', "\n";
        PHP;

    return Process::run(['php', '-r', $prelude, $wpContent, json_encode(['REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', ...$server]), json_encode($query), $plugin])->output();
}

describe('delen', function (): void {
    it('start een tunnel op de achtergrond en bewaart link, proces en eindtijd', function (): void {
        $result = $this->server->run(['share', 'demo', '--minutes', '60']);

        expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
            ->and($result->output())->toContain('URL: https://proef-123.trycloudflare.com', 'Gedeeld tot')
            ->and($result->output())->not->toContain('api.trycloudflare.com')
            ->and($this->server->calls())->toContain('ddev start', 'ddev share --provider=cloudflared');

        $state = shareState($this->state);
        expect($state['url'])->toBe('https://proef-123.trycloudflare.com')
            ->and((int) $state['expires'] - (int) $state['started'])->toBe(3600)
            ->and(processAlive((int) $state['pid']))->toBeTrue()
            ->and(processAlive((int) $state['watchdog']))->toBeTrue();
    });

    it('geeft de bestaande link als de site al gedeeld wordt', function (): void {
        $this->server->run(['share', 'demo'])->throw();
        $pid = shareState($this->state)['pid'];

        $again = $this->server->run(['share', 'demo']);

        expect($again->output())->toContain('al gedeeld', 'URL: https://proef-123.trycloudflare.com')
            ->and(shareState($this->state)['pid'])->toBe($pid)
            ->and(substr_count($this->server->calls(), 'ddev share'))->toBe(1);
    });

    it('stopt de tunnel en de bewaker en ruimt de status op', function (): void {
        $this->server->run(['share', 'demo'])->throw();
        $state = shareState($this->state);

        $stop = $this->server->run(['share', 'demo', '--stop']);
        usleep(200_000);

        expect($stop->output())->toContain('gestopt')
            ->and($this->state)->not->toBeFile()
            ->and(processAlive((int) $state['pid']))->toBeFalse()
            ->and(processAlive((int) $state['watchdog']))->toBeFalse();
    });

    it('meldt de status als JSON en in de sitelijst', function (): void {
        $this->server->run(['share', 'demo', '--minutes', '240'])->throw();

        $status = json_decode($this->server->run(['share', 'demo', '--status', '--json'])->output(), true);
        $site = json_decode($this->server->run(['info', 'demo', '--json'])->output(), true);

        expect($status['url'])->toBe('https://proef-123.trycloudflare.com')
            ->and(Carbon::parse($status['expires_at'])->diffInMinutes(Carbon::parse($status['started_at'])))->toEqual(-240)
            ->and($site['share'])->toBe($status);
    });

    it('ruimt een verlopen of gestopte deellink op', function (array $state): void {
        // 'share' in de opdrachtregel: wpopen stopt alleen processen die echt van een deellink zijn
        $tunnel = Process::start(['bash', '-c', 'sleep 30; : share']);
        file_put_contents($this->state, collect([
            'pid' => $state['pid'] ?? $tunnel->id(),
            'url' => 'https://oud-1.trycloudflare.com',
            'started' => time() - 7200,
            'expires' => $state['expires'],
        ])->map(fn (mixed $value, string $key): string => "{$key}={$value}")->implode("\n")."\n");

        expect(json_decode($this->server->run(['info', 'demo', '--json'])->output(), true)['share'])->toBeNull();

        $status = $this->server->run(['share', 'demo', '--status', '--json']);
        usleep(200_000);

        expect(trim($status->output()))->toBe('null')
            ->and($this->state)->not->toBeFile()
            ->and($tunnel->running())->toBe(isset($state['pid']));
        $tunnel->stop();
    })->with([
        'tijd voorbij' => [['expires' => time() - 60]],
        'proces weg' => [['pid' => 999999, 'expires' => time() + 3600]],
    ]);

    it('installeert cloudflared de eerste keer zelf in ~/.local/bin', function (): void {
        unlink($this->server->bin.'/cloudflared');
        $download = $this->server->root.'/download/cloudflared-linux';
        mkdir(dirname($download));
        file_put_contents($download, "#!/usr/bin/env bash\necho 'cloudflared version 2026.9.0 (built 2026-09-01)'\n");

        $result = $this->server->run(['share', 'demo'], ['WPO_CLOUDFLARED_URL' => 'file://'.$download]);
        $installed = $this->server->home.'/.local/bin/cloudflared';

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($result->output())->toContain('cloudflared geïnstalleerd')
            ->and(is_executable($installed))->toBeTrue()
            ->and($this->server->calls())->toContain('cloudflared='.$installed);
    })->skip(fn (): bool => Process::run(['bash', '-c', 'PATH=/usr/local/bin:/usr/bin:/bin command -v cloudflared'])->successful(), 'cloudflared staat al op deze computer');

    it('weigert een download die geen werkende cloudflared is', function (): void {
        unlink($this->server->bin.'/cloudflared');
        $download = $this->server->root.'/kapot';
        file_put_contents($download, "<html>niet gevonden</html>\n");

        $result = $this->server->run(['share', 'demo'], ['WPO_CLOUDFLARED_URL' => 'file://'.$download]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('werkt niet')
            ->and($this->server->home.'/.local/bin/cloudflared')->not->toBeFile();
    })->skip(fn (): bool => Process::run(['bash', '-c', 'PATH=/usr/local/bin:/usr/bin:/bin command -v cloudflared'])->successful(), 'cloudflared staat al op deze computer');

    it('meldt het als de tunnel niet start', function (): void {
        $result = $this->server->run(['share', 'demo'], ['FAKE_SHARE_FAILS' => '1', 'WPO_SHARE_WAIT' => '3']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('Kon geen deellink maken', 'tunnel kon niet starten')
            ->and($this->state)->not->toBeFile();
    });

    it('weigert ongeldige duur, niet-gebouwde sites en multisites', function (array $arguments, ?callable $setup, string $message): void {
        $setup && $setup($this->server);

        $result = $this->server->run(['share', ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message)
            ->and($this->server->calls())->not->toContain('ddev share');
    })->with([
        'duur' => [['demo', '--minutes', '5000'], null, 'tussen 5 en 1440'],
        'niet gebouwd' => [['demo'], fn (FakeServer $server) => unlink($server->sites.'/demo/.wpopen-ready'), 'nog niet lokaal gebouwd'],
        'multisite' => [['demo'], fn (FakeServer $server) => file_put_contents($server->sites.'/demo/wp-config.php', "<?php define( 'MULTISITE', true );\n"), 'multisite'],
    ]);
});

describe('lokale mu-plugin', function (): void {
    beforeEach(function (): void {
        $this->server->run(['login', 'demo'])->throw();
        $this->plugin = $this->server->sites.'/demo/wp-content/mu-plugins/wpopen-local.php';
        file_put_contents($this->state, "pid=999999\nurl=https://proef-123.trycloudflare.com\nstarted=".time()."\nexpires=".(time() + 3600)."\n");
    });

    it('schrijft het lokale adres en de afscherming in de mu-plugin', function (): void {
        expect(file_get_contents($this->plugin))
            ->toContain("'demo.ddev.site'", '.wpopen-share', 'Beheer is uitgeschakeld via de deellink.', "'127.0.0.1'", '1025', 'X-Robots-Tag: noindex, nofollow')
            ->not->toContain('__WPO_LOCAL_HOST__')
            ->and(Process::run(['php', '-l', $this->plugin])->successful())->toBeTrue();
    });

    it('blokkeert beheer, inloggen en gebruikerslijsten via de deellink', function (string $uri, string $script, array $query): void {
        $output = requestThroughPlugin($this->plugin, dirname($this->plugin, 2), ['HTTP_HOST' => 'proef-123.trycloudflare.com', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => $script], $query);

        expect($output)->toContain('Beheer is uitgeschakeld via de deellink.')
            ->not->toContain('home=');
    })->with([
        'wp-admin' => ['/wp-admin/', '/wp-admin/index.php', []],
        'wp-admin zonder slash' => ['/wp-admin', '/index.php', []],
        'wp-login' => ['/wp-login.php', '/wp-login.php', []],
        'xmlrpc' => ['/xmlrpc.php', '/xmlrpc.php', []],
        'inloglink' => ['/?wpopen-login=abc', '/index.php', ['wpopen-login' => 'abc']],
        'gebruikers via wp-json' => ['/wp-json/wp/v2/users', '/index.php', []],
        'gebruikers via rest_route' => ['/?rest_route=/wp/v2/users/1', '/index.php', ['rest_route' => '/wp/v2/users/1']],
    ]);

    it('toont de website via de deellink op het deeladres, zonder ingelogde gebruiker', function (string $uri, string $script): void {
        $output = requestThroughPlugin($this->plugin, dirname($this->plugin, 2), ['HTTP_HOST' => 'proef-123.trycloudflare.com', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => $script]);

        expect($output)->toContain(
            'home=https://proef-123.trycloudflare.com',
            'user=0',
            'href="https://proef-123.trycloudflare.com/contact/"',
            '"https:\/\/proef-123.trycloudflare.com\/x"',
        )->not->toContain('demo.ddev.site');
    })->with([
        'pagina' => ['/contact/', '/index.php'],
        'formulier via admin-ajax' => ['/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php'],
    ]);

    it('laat de lokale site zelf ongemoeid', function (): void {
        $output = requestThroughPlugin($this->plugin, dirname($this->plugin, 2), ['HTTP_HOST' => 'demo.ddev.site', 'REQUEST_URI' => '/wp-admin/', 'SCRIPT_NAME' => '/wp-admin/index.php']);

        expect($output)->toContain('home=https://demo.ddev.site', 'user=1', 'href="https://demo.ddev.site/contact/"');
    });

    it('sluit een verlopen of onbekende deellink af', function (string $host, int $expires): void {
        file_put_contents($this->state, "pid=999999\nurl=https://proef-123.trycloudflare.com\nstarted=1\nexpires={$expires}\n");

        $output = requestThroughPlugin($this->plugin, dirname($this->plugin, 2), ['HTTP_HOST' => $host]);

        expect($output)->toContain('Deze deellink is verlopen.')->not->toContain('home=');
    })->with([
        'verlopen' => ['proef-123.trycloudflare.com', time() - 60],
        'andere tunnel' => ['iets-anders.trycloudflare.com', time() + 3600],
    ]);

    it('laat mailplugins die zelf versturen lokaal niet laden', function (): void {
        $output = requestThroughPlugin($this->plugin, dirname($this->plugin, 2), ['HTTP_HOST' => 'demo.ddev.site']);

        expect($output)->toContain("mailers=forminator/forminator.php\n");
    });
});

describe('Mailpit', function (): void {
    beforeEach(function (): void {
        // Mailpit-API van de lokale site nabootsen
        $this->server->fakeCommand('curl', <<<'BASH'
            #!/usr/bin/env bash
            echo "curl $*" >> "$FAKE_LOG"
            case "$*" in
              *":8026/api/v1/messages"*) [ -n "${FAKE_MAILPIT_DOWN:-}" ] && exit 7; echo '{"total":3,"unread":1,"messages":[]}';;
            esac
            BASH);
    });

    it('geeft het adres en het aantal mails als JSON', function (): void {
        $result = $this->server->run(['mailpit', 'demo', '--json']);

        expect(json_decode($result->output(), true))->toBe(['url' => 'https://demo.ddev.site:8026', 'total' => 3, 'unread' => 1]);
    });

    it('geeft geen aantallen als Mailpit niet bereikbaar is', function (): void {
        $result = $this->server->run(['mailpit', 'demo', '--json'], ['FAKE_MAILPIT_DOWN' => '1']);

        expect(json_decode($result->output(), true))->toBe(['url' => 'https://demo.ddev.site:8026', 'total' => null, 'unread' => null]);
    });

    it('stuurt een testmail via WordPress naar een adres dat nergens bestaat', function (): void {
        $result = $this->server->run(['mailpit', 'demo', '--test']);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($result->output())->toContain('Testmail verstuurd')
            ->and($this->server->calls())->toContain('wp_mail( "test@example.test"')
            ->and($this->server->sites.'/demo/wp-content/mu-plugins/wpopen-local.php')->toBeFile();
    });

    it('zet de mail van een bestaande Laravel-site alsnog naar Mailpit', function (): void {
        $site = $this->server->laravelSite();
        mkdir($site['local'].'/.ddev');
        file_put_contents($site['local'].'/.env', "APP_KEY=base64:abc\nMAIL_MAILER=ses\nMAIL_HOST=smtp.mailgun.org\nMAIL_FROM_ADDRESS=info@shop.test\n");
        chmod($site['local'].'/.env', 0600);

        $this->server->run(['fix', 'shop'])->throw();
        $env = file_get_contents($site['local'].'/.env');

        expect($env)->toContain("APP_KEY=base64:abc\n", "MAIL_MAILER=smtp\n", "MAIL_HOST=127.0.0.1\n", "MAIL_PORT=1025\n", "MAIL_FROM_ADDRESS=info@shop.test\n")
            ->not->toContain('mailgun')
            ->and(fileperms($site['local'].'/.env') & 0777)->toBe(0600);
    });
});
