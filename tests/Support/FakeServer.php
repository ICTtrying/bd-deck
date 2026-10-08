<?php

namespace Tests\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Een "live server" in een tijdelijke map. Nep-versies van ssh, rsync en wp in PATH sturen
 * alles wat wpopen naar de server zou sturen naar deze map, zodat het echte script getest
 * wordt zonder ooit een echte site te raken.
 */
final class FakeServer
{
    public readonly string $home;

    public readonly string $bin;

    public readonly string $sites;

    public readonly string $remoteRoot;

    public readonly string $remote;

    public readonly string $log;

    private function __construct(public readonly string $root)
    {
        $this->home = $root.'/home';
        $this->bin = $root.'/bin';
        $this->sites = $root.'/home/wp-sites';
        $this->remoteRoot = $root.'/server/public_html';
        $this->remote = $this->remoteRoot.'/wp-content';
        $this->log = $root.'/calls.log';
    }

    public static function create(): self
    {
        $server = new self(sys_get_temp_dir().'/wpopen-test-'.bin2hex(random_bytes(6)));

        foreach ([$server->home.'/.config/wpsites', $server->bin, $server->sites, $server->remote.'/themes/demo', $server->remote.'/plugins/eigen'] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        $server->stub('ssh', <<<'BASH'
            #!/usr/bin/env bash
            while [ $# -gt 0 ]; do
              case "$1" in
                -o|-p|-i|-l) shift 2;;
                -*) shift;;
                *) break;;
              esac
            done
            target="$1"; shift
            echo "ssh $target $*" >> "$FAKE_LOG"
            [ $# -eq 0 ] && exit 0
            exec bash -c "$*"
            BASH);

        $server->stub('rsync', <<<'BASH'
            #!/usr/bin/env bash
            args=()
            while [ $# -gt 0 ]; do
              case "$1" in
                -e) shift 2;;
                *@*:*) args+=("${1#*:}"); shift;;
                *) args+=("$1"); shift;;
              esac
            done
            echo "rsync ${args[*]}" >> "$FAKE_LOG"
            exec /usr/bin/rsync "${args[@]}"
            BASH);

        $server->stub('wp', <<<'BASH'
            #!/usr/bin/env bash
            echo "wp $*" >> "$FAKE_LOG"
            case "$*" in
              *"core version"*) echo "6.8.1";;
              *"core is-installed"*) exit 0;;
              *"config get table_prefix"*) echo "${FAKE_REMOTE_PREFIX:-wp_}";;
              *"db import"*) cat > "$FAKE_IMPORTED";;
              *"option get home"*domains/tweede.test*) echo "https://tweede.test";;
              *"option get home"*) echo "https://tijdelijk.example.test";;
              *eval*) echo "✓ Cache geleegd (0 transients)";;
            esac
            BASH);

        // lokaal DDEV: alleen wat verhuizen nodig heeft; de export schrijft een herkenbare dump
        $server->stub('ddev', <<<'BASH'
            #!/usr/bin/env bash
            echo "ddev $*" >> "$FAKE_LOG"
            case "$*" in
              *"db prefix"*) echo "lokaal_";;
              *"search-replace"*)
                for a in "$@"; do case "$a" in --export=*) printf 'DROP TABLE IF EXISTS `lokaal_options`;\nINSERT INTO `lokaal_options` VALUES (1,\x27siteurl\x27,\x27https://nieuw.example.test\x27);\n' > "${a#--export=}";; esac; done;;
            esac
            exit 0
            BASH);

        // database- en composer-gereedschap op de "server": loggen wat er gebeurt, inclusief of het wachtwoord via de omgeving komt
        $server->stub('mysqldump', <<<'BASH'
            #!/usr/bin/env bash
            echo "mysqldump $* (MYSQL_PWD=${MYSQL_PWD:-})" >> "$FAKE_LOG"
            echo "-- dump van $(pwd)"
            BASH);
        $server->stub('composer', <<<'BASH'
            #!/usr/bin/env bash
            echo "composer $* in $(pwd)" >> "$FAKE_LOG"
            BASH);

        $server->writeFile('themes/demo/style.css', "/* Theme Name: Demo */\n");
        $server->writeFile('themes/demo/functions.php', "<?php // telefoon 020\n");
        $server->writeFile('plugins/eigen/eigen.php', "<?php // plugin\n");

        file_put_contents($server->home.'/.config/wpsites/sites', "demo|wesley@demo.test||{$server->remote}|ssh|other|https://demo.test\n");

        return $server;
    }

    /**
     * Lokale kopie zoals `wpopen build` die achterlaat, zonder DDEV.
     */
    public function buildLocalSite(): string
    {
        $wpContent = $this->sites.'/demo/wp-content';
        File::copyDirectory($this->remote, $wpContent);
        file_put_contents($this->sites.'/demo/.wpopen-ready', '');
        file_put_contents($wpContent.'/.gitignore', "/*\n!/.gitignore\n!/themes/\n/themes/*\n!/themes/demo/\n");

        $this->git($wpContent, ['init', '-q', '-b', 'main']);
        $this->git($wpContent, ['add', '-A']);
        $this->git($wpContent, ['commit', '-qm', 'Start: live-staat']);
        $this->git($wpContent, ['checkout', '-q', '-b', 'dev']);

        return $wpContent;
    }

    /**
     * Laravel-project op de "server" en een lokale kopie zoals `wpopen build` die achterlaat.
     *
     * @return array{remote: string, local: string}
     */
    public function laravelSite(): array
    {
        $remote = $this->home.'/domains/shop.test/laravel';
        $files = [
            'artisan' => "<?php file_put_contents(getenv('FAKE_LOG'), 'artisan '.implode(' ', array_slice(\$argv, 1)).\"\\n\", FILE_APPEND);\n",
            'composer.json' => '{"require": {"laravel/framework": "^12.0"}}',
            'composer.lock' => '{"v": 1}',
            '.env' => "APP_URL=https://shop.test\nDB_CONNECTION=mysql\nDB_DATABASE=shop\nDB_USERNAME=shop\nDB_PASSWORD=\"geheim wachtwoord\"\nSTRIPE_SECRET=sk_live_123\n",
            'app/Http/Controllers/HomeController.php' => "<?php // home\n",
            'routes/web.php' => "<?php // routes\n",
            'vendor/autoload.php' => "<?php // vendor\n",
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($remote.'/'.$path));
            file_put_contents($remote.'/'.$path, $contents);
        }

        $local = $this->sites.'/shop';
        File::copyDirectory($remote, $local);
        File::deleteDirectory($local.'/vendor');
        unlink($local.'/.env');
        file_put_contents($local.'/.wpopen-ready', '');
        $this->git($local, ['init', '-q', '-b', 'main']);
        file_put_contents($local.'/.git/info/exclude', "/.wpopen-*\n/vendor/\n/.env\n");
        $this->git($local, ['add', '-A']);
        $this->git($local, ['commit', '-qm', 'Start: live-staat']);
        $this->git($local, ['tag', 'deployed']);
        $this->git($local, ['checkout', '-q', '-b', 'dev']);

        file_put_contents($this->home.'/.config/wpsites/sites', "shop|u1@shop.test||{$remote}|ssh|other|https://shop.test|laravel\n", FILE_APPEND);

        return ['remote' => $remote, 'local' => $local];
    }

    /**
     * @param  list<string>  $arguments
     */
    public function git(string $directory, array $arguments): string
    {
        return Process::path($directory)
            ->env(['HOME' => $this->home, 'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.test', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.test'])
            ->run(['git', ...$arguments])
            ->throw()
            ->output();
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function run(array $arguments, array $environment = []): ProcessResult
    {
        return Process::env([
            'HOME' => $this->home,
            'PATH' => $this->bin.':/usr/local/bin:/usr/bin:/bin',
            'WP_SITES_DIR' => $this->sites,
            'WPO_NONINTERACTIVE' => '1',
            'WPO_SKIP_HOSTS' => '1',
            // alleen in de nep-server zoeken, nooit in /var/www van de testcomputer
            'WPO_SEARCH_ROOTS' => $this->home,
            'FAKE_LOG' => $this->log,
            'FAKE_IMPORTED' => $this->root.'/imported.sql',
            'LC_ALL' => 'C.UTF-8',
            ...$environment,
        ])->timeout(60)->run(['bash', base_path('bin/wpopen'), ...$arguments]);
    }

    public function writeFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->remote.'/'.$path));
        file_put_contents($this->remote.'/'.$path, $contents);
    }

    public function readFile(string $path): ?string
    {
        return is_file($this->remote.'/'.$path) ? (string) file_get_contents($this->remote.'/'.$path) : null;
    }

    public function calls(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
    }

    public function imported(): ?string
    {
        return is_file($this->root.'/imported.sql') ? (string) file_get_contents($this->root.'/imported.sql') : null;
    }

    public function sitesFile(): string
    {
        return (string) file_get_contents($this->home.'/.config/wpsites/sites');
    }

    /**
     * @return list<string>
     */
    public function backups(string $site = 'demo'): array
    {
        return collect(glob($this->sites.'/.wpopen-backups/'.$site.'/*', GLOB_ONLYDIR) ?: [])->map(fn (string $path): string => basename($path))->sort()->values()->all();
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }

    private function stub(string $name, string $script): void
    {
        file_put_contents($this->bin.'/'.$name, $script."\n");
        chmod($this->bin.'/'.$name, 0755);
    }
}
