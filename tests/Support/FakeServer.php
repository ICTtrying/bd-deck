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
              *eval*) echo "✓ Cache geleegd (0 transients)";;
            esac
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
     */
    public function run(array $arguments): ProcessResult
    {
        return Process::env([
            'HOME' => $this->home,
            'PATH' => $this->bin.':/usr/local/bin:/usr/bin:/bin',
            'WP_SITES_DIR' => $this->sites,
            'WPO_NONINTERACTIVE' => '1',
            'FAKE_LOG' => $this->log,
            'LC_ALL' => 'C.UTF-8',
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

    public function sitesFile(): string
    {
        return (string) file_get_contents($this->home.'/.config/wpsites/sites');
    }

    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return collect(glob($this->sites.'/.wpopen-backups/demo/*', GLOB_ONLYDIR) ?: [])->map(fn (string $path): string => basename($path))->sort()->values()->all();
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
