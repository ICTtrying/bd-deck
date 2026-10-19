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
              # multisite: omzetten schrijft de constante, de test bepaalt de sitelijst en het nieuwe id
              *"multisite-convert"*) echo "define( 'MULTISITE', true );" >> wp-config.php;;
              *"site list"*) printf '%b' "${FAKE_SITE_LIST:-blog_id,domain\n1,nieuw.ddev.site\n}";;
              *wpmu_create_blog*) echo "${FAKE_NEW_BLOG_ID:-2}";;
              *"option get stylesheet"*) echo "enfold";;
              *"plugin list --status=active"*) printf 'forminator\nwpmudev-updates\n';;
              *"search-replace"*)
                for a in "$@"; do case "$a" in --export=*) printf 'DROP TABLE IF EXISTS `lokaal_options`;\nINSERT INTO `lokaal_options` VALUES (1,\x27siteurl\x27,\x27https://nieuw.example.test\x27);\n' > "${a#--export=}";; esac; done;;
              # pakketbeheer: de test bepaalt via de omgeving wat er "verandert" of mislukt
              *"composer outdated"*) printf '%s' "${FAKE_COMPOSER_OUTDATED:-}";;
              *"composer update"*"--dry-run"*) [ -z "${FAKE_COMPOSER_CONFLICT:-}" ] || ! grep -q "$FAKE_COMPOSER_CONFLICT" composer.json || { echo "Your requirements could not be resolved to an installable set of packages." >&2; exit 2; };;
              *"composer update"*) [ -z "${FAKE_COMPOSER_LOCK:-}" ] || printf '%s' "$FAKE_COMPOSER_LOCK" > composer.lock;;
              *"npm outdated"*) printf '%s' "${FAKE_NPM_OUTDATED:-}"; [ -z "${FAKE_NPM_OUTDATED:-}" ] || exit 1;;
              *"npm update"*) [ -z "${FAKE_NPM_FAILS:-}" ] || exit 1;;
            esac
            exit 0
            BASH);

        // alleen de lokale site nabootsen; al het andere verkeer gaat naar de echte curl
        $server->stub('curl', <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              *.ddev.site*) echo "curl $*" >> "$FAKE_LOG"; printf '%s' "${FAKE_LOCAL_HTTP:-000}";;
              *) exec /usr/bin/curl "$@";;
            esac
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

    /**
     * Nep-internet voor de go-live-checklist: alleen deze adressen bestaan, al het andere heeft geen DNS.
     * Daarnaast antwoordt WP-CLI met FAKE_GOLIVE (feiten van live) en FAKE_LOCAL_URLS/FAKE_OLD_URLS (tellingen),
     * en doet lftp alsof uploaden lukt, zodat ook sites met alleen SFTP getest kunnen worden (FAKE_LIVE_PHP).
     *
     * @param  array<string, array{status?: int, body?: string, type?: string, redirect?: string, error?: int, message?: string}>  $pages
     */
    public function fakeWeb(array $pages): void
    {
        $web = $this->root.'/web';
        File::ensureDirectoryExists($web);
        File::ensureDirectoryExists($this->root.'/lftp');

        foreach ($pages as $url => $page) {
            $key = preg_replace('/[^A-Za-z0-9.-]/', '_', $url);
            $error = $page['error'] ?? 0;
            $effective = $page['redirect'] ?? $url;

            file_put_contents("{$web}/{$key}.meta", implode("\n", [
                $error, $error > 0 ? '000' : ($page['status'] ?? 200), $effective === $url ? 0 : 1, $effective,
                $page['type'] ?? 'text/html; charset=UTF-8', $page['message'] ?? 'nep-fout',
            ])."\n");
            file_put_contents("{$web}/{$key}.body", $page['body'] ?? '');
        }

        $this->stub('curl', str_replace('__WEB__', $web, <<<'BASH'
            #!/usr/bin/env bash
            url=""; out=/dev/null; fmt=""
            while [ $# -gt 0 ]; do
              case "$1" in
                -o) out="$2"; shift 2;;
                -w) fmt="$2"; shift 2;;
                -A|-d|--max-time|--max-redirs) shift 2;;
                http://*|https://*) url="$1"; shift;;
                *) shift;;
              esac
            done
            echo "curl $url" >> "$FAKE_LOG"
            case "$url" in
              *.ddev.site*) printf '%s' "${FAKE_LOCAL_HTTP:-000}"; exit 0;;
              */wpo-*.php) printf '%s\n-- wpopen-done\n' "${FAKE_LIVE_PHP:-}" > "$out"; printf '200'; exit 0;;
            esac
            key=$(printf '%s' "$url" | tr -c 'A-Za-z0-9.-' '_')
            rc=6; code=000; redirects=0; effective="$url"; type=""; message="Could not resolve host"
            if [ -f "__WEB__/$key.meta" ]; then
              { read -r rc; read -r code; read -r redirects; read -r effective; read -r type; read -r message; } < "__WEB__/$key.meta"
              cat "__WEB__/$key.body" > "$out"
            fi
            f="${fmt//%\{http_code\}/$code}"; f="${f//%\{num_redirects\}/$redirects}"
            f="${f//%\{url_effective\}/$effective}"; f="${f//%\{content_type\}/$type}"
            printf '%s' "$f"
            [ "$rc" = 0 ] || { echo "curl: ($rc) $message" >&2; exit "$rc"; }
            exit 0
            BASH));

        if (! is_file($this->bin.'/wp-base')) {
            rename($this->bin.'/wp', $this->bin.'/wp-base');
        }

        $this->stub('wp', <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              *"--dry-run"*"--format=count"*) echo "wp $*" >> "$FAKE_LOG"; case "$*" in *ddev.site*) echo "${FAKE_LOCAL_URLS:-0}";; *) echo "${FAKE_OLD_URLS:-0}";; esac; exit 0;;
              *wpo-golive*) echo "wp $*" >> "$FAKE_LOG"; printf '%s\n' "${FAKE_GOLIVE:-}"; exit 0;;
            esac
            exec "$(dirname "$0")/wp-base" "$@"
            BASH);

        $this->stub('lftp', str_replace('__UPLOADS__', $this->root.'/lftp', <<<'BASH'
            #!/usr/bin/env bash
            [ "${1:-}" = -f ] || exit 0
            cat "$2" >> "$FAKE_LOG"
            while IFS= read -r line; do
              case "$line" in "put -O "*) eval "set -- $line"; cp "$4" "__UPLOADS__/";; esac
            done < "$2"
            exit 0
            BASH));
    }

    /**
     * Alles wat via lftp naar een SFTP-site geüpload is, achter elkaar.
     */
    public function lftpUploads(): string
    {
        return collect(glob($this->root.'/lftp/*') ?: [])->map(fn (string $path): string => (string) file_get_contents($path))->implode("\n");
    }

    /**
     * Sitelijst zoals `wpopen export-sites` die maakt; ontbrekende velden krijgen een standaardwaarde.
     *
     * @param  list<array<string, mixed>>  $sites
     * @param  array<string, mixed>  $document  overschrijft de kop, bv. format of version
     */
    public function siteListFile(array $sites, array $document = []): string
    {
        $path = $this->root.'/sitelijst-'.bin2hex(random_bytes(3)).'.json';
        $defaults = ['target' => 'wesley@klant.test', 'ssh_options' => '', 'remote' => '/home/wesley/public_html/wp-content', 'mode' => 'ssh', 'provider' => 'other', 'live_url' => null, 'type' => 'wordpress', 'public_dir' => null];

        file_put_contents($path, json_encode([
            'format' => 'bd-deck-sites',
            'version' => 1,
            'exported_at' => '2026-10-16T12:00:00Z',
            'sites' => array_map(fn (array $site): array => [...$defaults, ...$site], $sites),
            ...$document,
        ]));

        return $path;
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }

    /**
     * Eigen nep-commando in PATH, bv. cloudflared of een curl die Mailpit nabootst.
     */
    public function fakeCommand(string $name, string $script): void
    {
        $this->stub($name, $script);
    }

    /**
     * Extra gevallen voor de nep-ddev (bv. `ddev share`); al het andere gaat naar de standaard-stub.
     */
    public function extendDdev(string $cases): void
    {
        if (! is_file($this->bin.'/ddev-base')) {
            rename($this->bin.'/ddev', $this->bin.'/ddev-base');
        }

        $this->stub('ddev', "#!/usr/bin/env bash\ncase \"\$*\" in\n{$cases}\nesac\nexec \"\$(dirname \"\$0\")/ddev-base\" \"\$@\"");
    }

    /**
     * Voor `wpopen safe-update`: lokaal bijwerken, pagina's opvragen en PHP-fouten in `ddev logs`, plus SFTP via lftp.
     * De bestaande nep-ddev, -wp en -curl blijven het vangnet; deze laag handelt alleen af wat updates nodig hebben.
     * Een bestand met FATAL in een plugin of thema laat pagina's een 500 geven (lokaal ook met een PHP-fout in de log);
     * KAPOT_OP_LIVE doet dat alleen op live.
     *
     * @param  array{core?: list<array<string, mixed>>, plugins?: list<array<string, mixed>>, themes?: list<array<string, mixed>>}  $updates
     */
    public function fakeWordPressUpdates(array $updates): void
    {
        $this->setAvailableUpdates($updates);
        File::ensureDirectoryExists($this->sites.'/demo');
        file_put_contents($this->sites.'/demo/wp-config.php', "<?php\n\$table_prefix = 'wp_';\n");

        foreach (['ddev', 'wp', 'curl'] as $name) {
            if (! is_file($this->bin.'/'.$name.'-base')) {
                rename($this->bin.'/'.$name, $this->bin.'/'.$name.'-base');
            }
        }

        $this->stub('ddev', <<<'BASH'
            #!/usr/bin/env bash
            root='__ROOT__'
            proj=$PWD
            while [ "$proj" != / ] && [ ! -f "$proj/.wpopen-ready" ]; do proj=$(dirname "$proj"); done
            wpc="$proj/wp-content"
            case "$*" in
              logs*) cat "$root/web.log" 2>/dev/null; exit 0;;
              *"option get siteurl"*) echo "https://$(basename "$proj").ddev.site"; exit 0;;
              *'"wpo-" . "ok"'*) echo "wpo-ok"; exit 0;;
              *"post list"*) echo "https://$(basename "$proj").ddev.site/over-ons/"; exit 0;;
              *"plugin list --fields=name,version"*)
                echo "name,version"
                for d in "$wpc"/plugins/*/; do echo "$(basename "$d"),$(cat "$d"*.php 2>/dev/null | sed -nE 's/.*Version: *([0-9.]+).*/\1/p' | sed -n 1p)"; done
                exit 0;;
              *"theme list --fields=name,version"*)
                echo "name,version"
                for d in "$wpc"/themes/*/; do echo "$(basename "$d"),$(sed -nE 's/.*Version: *([0-9.]+).*/\1/p' "$d/style.css" 2>/dev/null | sed -n 1p)"; done
                exit 0;;
              *"core update-db"*) echo "ddev $*" >> "$FAKE_LOG"; exit 0;;
              *"core update"*)
                echo "ddev $*" >> "$FAKE_LOG"
                for a in "$@"; do case "$a" in --version=*) echo "${a#--version=}" > "$proj/.fake-core";; esac; done
                exit 0;;
              *"core version"*) cat "$proj/.fake-core" 2>/dev/null || echo "6.8.1"; exit 0;;
              "wp plugin update "*|"wp theme update "*)
                echo "ddev $*" >> "$FAKE_LOG"
                package="$root/packages/$2s/$4"
                [ -d "$package" ] || { echo "Error: geen update voor $4" >&2; exit 1; }
                rm -rf "$wpc/$2s/$4"; cp -a "$package" "$wpc/$2s/$4"
                # iemand past live aan terwijl de update loopt
                if [ -n "${FAKE_LIVE_CHANGE:-}" ]; then echo "<?php // hotfix" > "$root/server/public_html/wp-content/$FAKE_LIVE_CHANGE"; fi
                echo "Success: Updated 1 of 1 $2s."; exit 0;;
              export-db*)
                for a in "$@"; do case "$a" in --file=*) echo "lokale database" | gzip > "${a#--file=}";; esac; done
                exit 0;;
              import-db*) echo "ddev $*" >> "$FAKE_LOG"; cat > /dev/null; exit 0;;
            esac
            exec "$(dirname "$0")/ddev-base" "$@"
            BASH);

        $this->stub('wp', <<<'BASH'
            #!/usr/bin/env bash
            root='__ROOT__'
            case "$*" in
              *wp_update_plugins*) echo "wp eval (updates)" >> "$FAKE_LOG"; cat "$root/updates.json"; echo; exit 0;;
              *"core update-db"*) echo "wp $*" >> "$FAKE_LOG"; exit 0;;
              *"core update"*)
                echo "wp $*" >> "$FAKE_LOG"
                for a in "$@"; do case "$a" in --version=*) echo "${a#--version=}" > "$root/server/core-version";; esac; done
                exit 0;;
              *"core version"*) cat "$root/server/core-version" 2>/dev/null || echo "6.8.1"; exit 0;;
            esac
            exec "$(dirname "$0")/wp-base" "$@"
            BASH);

        // nooit echt netwerk: lokale en live pagina's worden beantwoord vanuit de bestanden van de site
        $this->stub('curl', <<<'BASH'
            #!/usr/bin/env bash
            root='__ROOT__'
            url=""; out=""; post=0
            while [ $# -gt 0 ]; do
              case "$1" in
                -o) out="$2"; shift;;
                -d) post=1; shift;;
                -w|-b|-c|--max-time|--max-redirs) shift;;
                http*) url="$1";;
              esac
              shift
            done
            echo "curl $url" >> "$FAKE_LOG"
            if [[ "$url" == *.ddev.site* ]]; then
              host="${url#*//}"; host="${host%%/*}"; dir="$root/home/wp-sites/${host%.ddev.site}/wp-content"; markers=(-e FATAL)
            else
              dir="$root/server/public_html/wp-content"; markers=(-e FATAL -e KAPOT_OP_LIVE)
            fi
            # tijdelijk PHP-bestand van live_php (sites met alleen SFTP)
            if [ $post = 1 ]; then
              name="${url##*/}"
              { if grep -qs wp_update_plugins "$root/server/public_html/$name"; then cat "$root/updates.json"; echo; else echo "✓ Cache geleegd (0 transients)"; fi
                echo "-- wpopen-done"; } > "${out:-/dev/stdout}"
              printf 200; exit 0
            fi
            broken=$(grep -rlsF "${markers[@]}" "$dir/plugins" "$dir/themes" | sed -n 1p)
            if [ -n "$broken" ]; then
              if [[ "$url" == *.ddev.site* ]]; then
                echo "$(date -u +%Y-%m-%dT%H:%M:%S.%NZ) [pool www] child 12 said into stderr: \"PHP Fatal error:  Uncaught Error: Call to undefined function nieuw() in /var/www/html/wp-content/${broken#$dir/}:3\"" >> "$root/web.log"
              fi
              printf 500; exit 0
            fi
            if [[ "$url" == *.ddev.site* ]] && grep -rqsF WARNING "$dir/plugins" "$dir/themes"; then
              echo "$(date -u +%Y-%m-%dT%H:%M:%S.%NZ) PHP Warning:  Undefined variable \$x in /var/www/html/wp-content/plugins/x.php on line 4" >> "$root/web.log"
            fi
            printf 200
            BASH);

        $this->stub('lftp', <<<'BASH'
            #!/usr/bin/env bash
            [ "$1" = -f ] || exit 1
            lcd=$PWD; rc=0
            while IFS= read -r line; do
              [ -n "$line" ] || continue
              eval "set -- $line"
              cmd=$1; shift
              case "$cmd" in set|open) continue;; esac
              echo "lftp $cmd $*" >> "$FAKE_LOG"
              case "$cmd" in
                lcd) lcd="$1";;
                mkdir) mkdir -p "${!#}";;
                put) f="$3"; [[ "$f" = /* ]] || f="$lcd/$f"; mkdir -p "$2"; cp "$f" "$2/" || rc=1;;
                get) mkdir -p "$2"; cp "$3" "$2/" 2>/dev/null || rc=1;;
                rm) rm -f "${!#}";;
                cat) cat "$1" 2>/dev/null || rc=1;;
                cls) ls -1 "${!#}" || rc=1;;
                mirror)
                  paths=()
                  while [ $# -gt 0 ]; do case "$1" in -x) shift;; -*) ;; *) paths+=("$1");; esac; shift; done
                  mkdir -p "${paths[1]}"; cp -a "${paths[0]}/." "${paths[1]}/" 2>/dev/null || rc=1;;
              esac
            done < "$2"
            exit $rc
            BASH);
    }

    /**
     * @param  array{core?: list<array<string, mixed>>, plugins?: list<array<string, mixed>>, themes?: list<array<string, mixed>>}  $updates
     */
    public function setAvailableUpdates(array $updates): void
    {
        file_put_contents($this->root.'/updates.json', json_encode(['core' => [], 'plugins' => [], 'themes' => [], ...$updates]));
    }

    /**
     * Nieuwe versie van een plugin of thema, zoals wordpress.org hem zou leveren.
     *
     * @param  'plugins'|'themes'  $kind
     * @param  array<string, string>  $files
     */
    public function addPackage(string $kind, string $slug, array $files): void
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->root."/packages/{$kind}/{$slug}/{$path}"));
            file_put_contents($this->root."/packages/{$kind}/{$slug}/{$path}", $contents);
        }
    }

    /**
     * De site heeft alleen SFTP: alles gaat via lftp en tijdelijke PHP-bestanden.
     */
    public function useSftp(): void
    {
        file_put_contents($this->home.'/.config/wpsites/sites', str_replace('|ssh|', '|sftp|', $this->sitesFile()));
    }

    private function stub(string $name, string $script): void
    {
        file_put_contents($this->bin.'/'.$name, str_replace('__ROOT__', $this->root, $script)."\n");
        chmod($this->bin.'/'.$name, 0755);
    }
}
