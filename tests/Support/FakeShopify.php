<?php

namespace Tests\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Een Shopify-"winkel" in een tijdelijke map. Een nep-versie van de Shopify CLI in PATH werkt op deze
 * winkel (themalijst, themabestanden, publiceren), zodat het echte wpopen getest wordt zonder ooit een
 * echte winkel te raken. De nep-CLI weigert en logt elke poging om rechtstreeks naar live te pushen.
 */
final class FakeShopify
{
    public const string STORE = 'demo-winkel.myshopify.com';

    public readonly string $home;

    public readonly string $bin;

    public readonly string $projects;

    public readonly string $store;

    public readonly string $log;

    /**
     * Eerste poort voor dev servers: een vrije poort van deze computer, zodat tests nooit van bezette poorten afhangen.
     */
    public readonly int $port;

    private function __construct(public readonly string $root)
    {
        $this->port = self::freePort();
        $this->home = $root.'/home';
        $this->bin = $root.'/bin';
        $this->projects = $root.'/projects';
        $this->store = $root.'/store';
        $this->log = $root.'/calls.log';
    }

    public static function create(): self
    {
        $shopify = new self(sys_get_temp_dir().'/wpopen-shopify-test-'.bin2hex(random_bytes(6)));

        foreach ([$shopify->home.'/.config/wpsites', $shopify->bin, $shopify->projects, $shopify->store.'/themes'] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        file_put_contents($shopify->log, '');
        $shopify->stub('shopify', self::cli());
        $shopify->stub('curl', "#!/usr/bin/env bash\necho 200");
        $shopify->setThemes([
            ['id' => 100, 'name' => 'Live thema', 'role' => 'live'],
            ['id' => 200, 'name' => 'Oud thema', 'role' => 'unpublished'],
        ]);
        $shopify->writeRemote(100, [
            'layout/theme.liquid' => "<html>live</html>\n",
            'templates/index.json' => "{\"sections\":{\"klant\":\"live\"}}\n",
            'config/settings_data.json' => "{\"current\":\"klant-live\"}\n",
            'sections/header-group.json' => "{\"header\":\"live\"}\n",
            'snippets/knop.liquid' => "<button>live</button>\n",
        ]);

        return $shopify;
    }

    private static function freePort(): int
    {
        // een reeks van 10 aaneengesloten poorten die allemaal vrij zijn
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $base = random_int(20000, 60000);
            $free = collect(range($base, $base + 9))->every(function (int $port): bool {
                $socket = @stream_socket_server('tcp://127.0.0.1:'.$port);

                if ($socket === false) {
                    return false;
                }

                fclose($socket);

                return true;
            });

            if ($free) {
                return $base;
            }
        }

        throw new RuntimeException('Geen vrije poorten gevonden voor de test.');
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function run(array $arguments, array $environment = []): ProcessResult
    {
        return Process::env([
            'HOME' => $this->home,
            'PATH' => $this->bin.':/usr/local/bin:/usr/bin:/bin',
            'WP_SITES_DIR' => $this->root.'/wp-sites',
            'WPO_SHOPIFY_DIR' => $this->projects,
            'WPO_SHOPIFY_PORT_BASE' => (string) $this->port,
            'WPO_NONINTERACTIVE' => '1',
            'WPO_SHOPIFY_WAIT' => '15',
            'WPO_SHOPIFY_LOGIN_WAIT' => '20',
            'WPO_SHOPIFY_TIMEOUT' => '30',
            'FAKE_SHOPIFY' => $this->store,
            'FAKE_LOG' => $this->log,
            'LC_ALL' => 'C.UTF-8',
            ...$environment,
        ])->timeout(90)->run(['bash', base_path('bin/wpopen'), ...$arguments]);
    }

    public function stub(string $name, string $script): void
    {
        file_put_contents($this->bin.'/'.$name, $script);
        chmod($this->bin.'/'.$name, 0755);
    }

    /**
     * Een themamap met een git-repo en het live thema als eerste commit, zoals de echte projecten.
     */
    public function project(string $name = 'demo'): string
    {
        $path = $this->projects.'/'.$name;

        foreach (['layout/theme.liquid' => "<html>live</html>\n", 'templates/index.json' => "{\"sections\":{\"klant\":\"live\"}}\n", 'config/settings_data.json' => "{\"current\":\"klant-live\"}\n", 'sections/header-group.json' => "{\"header\":\"live\"}\n", 'snippets/knop.liquid' => "<button>live</button>\n"] as $file => $contents) {
            File::ensureDirectoryExists(dirname($path.'/'.$file));
            file_put_contents($path.'/'.$file, $contents);
        }

        $this->git($path, ['init', '-q', '-b', 'main']);
        $this->git($path, ['add', '-A']);
        $this->git($path, ['commit', '-qm', 'Live thema, uitgangspunt']);

        return $path;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function git(string $directory, array $arguments): string
    {
        $result = Process::path($directory)->env(['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t', 'HOME' => $this->home])
            ->run(['git', ...$arguments]);

        return trim($result->output());
    }

    /**
     * Voegt een project toe aan de sitelijst van wpopen.
     *
     * @param  list<string>  $options
     */
    public function addProject(string $name = 'demo', array $options = []): string
    {
        $path = $this->project($name);
        $this->run(['shopify', 'add', $name, '--store', self::STORE, '--path', $path, ...$options])->throw();

        return $path;
    }

    /**
     * @param  list<array{id: int, name: string, role: string}>  $themes
     */
    public function setThemes(array $themes): void
    {
        file_put_contents($this->store.'/themes.json', json_encode($themes));
    }

    /**
     * @return list<array{id: int, name: string, role: string}>
     */
    public function themes(): array
    {
        return json_decode((string) file_get_contents($this->store.'/themes.json'), true);
    }

    /**
     * @return array{id: int, name: string, role: string}|null
     */
    public function theme(string $name): ?array
    {
        return collect($this->themes())->firstWhere('name', $name);
    }

    /**
     * @param  array<string, string>  $files
     */
    public function writeRemote(int $themeId, array $files): void
    {
        foreach ($files as $file => $contents) {
            File::ensureDirectoryExists(dirname($this->store.'/themes/'.$themeId.'/'.$file));
            file_put_contents($this->store.'/themes/'.$themeId.'/'.$file, $contents);
        }
    }

    public function readRemote(int $themeId, string $file): ?string
    {
        $path = $this->store.'/themes/'.$themeId.'/'.$file;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $offenses  per bestand: path en offenses
     */
    public function setCheckResult(array $offenses): void
    {
        file_put_contents($this->store.'/check.json', json_encode($offenses));
    }

    public function calls(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function sitesFile(): string
    {
        return (string) file_get_contents($this->home.'/.config/wpsites/sites');
    }

    public function stateDirectory(string $name = 'demo'): string
    {
        return $this->home.'/.config/wpsites/shopify/'.$name;
    }

    public function destroy(): void
    {
        // dev servers die een test heeft laten draaien, altijd stoppen
        foreach (glob($this->stateDirectory('*').'/dev') ?: [] as $state) {
            $pid = (int) trim((string) shell_exec('sed -n "s/^pid=//p" '.escapeshellarg($state)));

            if ($pid > 1 && str_contains((string) @file_get_contents('/proc/'.$pid.'/cmdline'), 'wpopen-shopify-dev')) {
                posix_kill(-$pid, SIGKILL);
            }
        }

        File::deleteDirectory($this->root);
    }

    private static function cli(): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # nep-Shopify CLI: werkt op de winkel in $FAKE_SHOPIFY
            echo "shopify $*" >> "$FAKE_LOG"
            [ -z "${SHOPIFY_FLAG_STORE_PASSWORD:-}" ] || echo "store-password-set" >> "$FAKE_LOG"
            args=("$@")
            has() { local a; for a in "${args[@]}"; do [ "$a" = "$1" ] && return 0; done; return 1; }
            val() { local i; for ((i = 0; i < ${#args[@]}; i++)); do [ "${args[$i]}" = "$1" ] && { echo "${args[$((i + 1))]}"; return; }; done; }
            vals() { local i; for ((i = 0; i < ${#args[@]}; i++)); do [ "${args[$i]}" = "$1" ] && echo "${args[$((i + 1))]}"; done; true; }

            [ "${args[0]}" = version ] && { echo "3.99.0"; exit 0; }

            # nooit rechtstreeks naar live: loggen en weigeren
            if [ "${args[0]} ${args[1]}" = "theme push" ] && { has --live || has --allow-live || has -l || has -a; }; then
              echo "LIVE-PUSH-POGING $*" >> "$FAKE_LOG"; echo "live push geweigerd door de nep-CLI" >&2; exit 99
            fi

            if [ "${args[0]} ${args[1]}" != "theme check" ] && [ -n "${FAKE_SHOPIFY_LOGGED_OUT:-}" ] && [ ! -f "$FAKE_SHOPIFY/logged-in" ]; then
              if has --no-input; then echo "Error: you must be logged in; --no-input disables login" >&2; exit 1; fi
              echo "To run this command, log in to Shopify."
              echo "User verification code: ABCD-EFGH"
              echo "Opened link to start the auth process: https://accounts.shopify.com/activate-with-code?device_code%5Buser_code%5D=ABCD-EFGH"
              sleep "${FAKE_SHOPIFY_LOGIN_DELAY:-2}"
              touch "$FAKE_SHOPIFY/logged-in"
            fi

            [ -z "${FAKE_SHOPIFY_FORBIDDEN:-}" ] || { echo "Error: You are not authorized to use the CLI to develop in the provided store." >&2; exit 1; }
            [ -z "${FAKE_SHOPIFY_NOSTORE:-}" ] || { echo "Error: Couldn't find the store $(val --store)" >&2; exit 1; }
            [ -z "${FAKE_SHOPIFY_ECHO_PASSWORD:-}" ] || echo "debug: wachtwoord is ${SHOPIFY_FLAG_STORE_PASSWORD:-}"

            sub="${args[0]} ${args[1]}"
            case "$sub" in
              "theme list")
                python3 - <<'PY'
            import json, os
            themes = json.load(open(os.environ["FAKE_SHOPIFY"] + "/themes.json"))
            print(json.dumps([{"id": t["id"], "name": t["name"], "role": t["role"], "shop": "demo"} for t in themes], indent=2))
            PY
                ;;
              "theme pull")
                id=$(val --theme); dest=$(val --path); mapfile -t only < <(vals --only)
                [ -d "$FAKE_SHOPIFY/themes/$id" ] || { echo "Error: theme $id not found" >&2; exit 1; }
                mkdir -p "$dest"
                ONLY="$(printf '%s\n' "${only[@]}")" SRC="$FAKE_SHOPIFY/themes/$id" DEST="$dest" python3 - <<'PY'
            import fnmatch, os, shutil
            only = [p for p in os.environ["ONLY"].split("\n") if p]
            src, dest = os.environ["SRC"], os.environ["DEST"]
            for root, _, files in os.walk(src):
                for f in files:
                    rel = os.path.relpath(os.path.join(root, f), src)
                    if only and not any(fnmatch.fnmatch(rel, p) for p in only):
                        continue
                    os.makedirs(os.path.dirname(os.path.join(dest, rel)), exist_ok=True)
                    shutil.copyfile(os.path.join(root, f), os.path.join(dest, rel))
                    print("downloaded " + rel)
            PY
                ;;
              "theme push")
                path=$(val --path); mapfile -t ignore < <(vals --ignore); mapfile -t only < <(vals --only)
                if has --unpublished; then
                  mode=new; name=$(val --theme)
                else
                  mode=update; name=$(val --theme)
                fi
                MODE=$mode NAME="$name" PATH_="$path" IGNORE="$(printf '%s\n' "${ignore[@]}")" ONLY="$(printf '%s\n' "${only[@]}")" STORE_="$(val --store)" JSONOUT="$(has --json && echo 1)" python3 - <<'PY'
            import fnmatch, json, os, shutil, sys
            base = os.environ["FAKE_SHOPIFY"]
            themes = json.load(open(base + "/themes.json"))
            name = os.environ["NAME"]
            if os.environ["MODE"] == "new":
                theme = {"id": max(t["id"] for t in themes) + 1, "name": name, "role": "unpublished"}
                themes.append(theme)
            else:
                theme = next((t for t in themes if str(t["id"]) == name or t["name"] == name), None)
                if theme is None:
                    print("Error: theme not found", file=sys.stderr); sys.exit(1)
                if theme["role"] == "live":
                    print("LIVE-PUSH-POGING naar live thema " + name, file=open(os.environ["FAKE_LOG"], "a"))
                    print("refusing to push to the live theme", file=sys.stderr); sys.exit(98)
            ignore = [p for p in os.environ["IGNORE"].split("\n") if p]
            only = [p for p in os.environ["ONLY"].split("\n") if p]
            src, dest = os.environ["PATH_"], "%s/themes/%d" % (base, theme["id"])
            for root, dirs, files in os.walk(src):
                dirs[:] = [d for d in dirs if d != ".git"]
                for f in files:
                    rel = os.path.relpath(os.path.join(root, f), src)
                    top = rel.split("/")[0]
                    if top not in ("assets", "blocks", "config", "layout", "locales", "sections", "snippets", "templates"):
                        continue
                    if any(fnmatch.fnmatch(rel, p) for p in ignore):
                        continue
                    if only and not any(fnmatch.fnmatch(rel, p) for p in only):
                        continue
                    os.makedirs(os.path.dirname(os.path.join(dest, rel)), exist_ok=True)
                    shutil.copyfile(os.path.join(root, f), os.path.join(dest, rel))
            json.dump(themes, open(base + "/themes.json", "w"))
            if os.environ.get("JSONOUT"):
                print(json.dumps({"theme": {"id": theme["id"], "name": theme["name"], "role": theme["role"], "shop": os.environ["STORE_"]}}))
            PY
                ;;
              "theme publish")
                has --force || { echo "Error: confirmation needed (use --force)" >&2; exit 1; }
                python3 - "$(val --theme)" <<'PY'
            import json, os, sys
            base = os.environ["FAKE_SHOPIFY"]
            themes = json.load(open(base + "/themes.json"))
            for t in themes:
                if str(t["id"]) == sys.argv[1]:
                    t["role"] = "live"
                elif t["role"] == "live":
                    t["role"] = "unpublished"
            json.dump(themes, open(base + "/themes.json", "w"))
            PY
                echo "Theme published"
                ;;
              "theme check")
                if [ -f "$FAKE_SHOPIFY/check.json" ]; then cat "$FAKE_SHOPIFY/check.json"; else echo "[]"; fi
                grep -q '"severity":"error"' "$FAKE_SHOPIFY/check.json" 2>/dev/null && exit 1
                exit 0
                ;;
              "theme dev")
                port=$(val --port)
                [ -z "${FAKE_SHOPIFY_DEV_FAIL:-}" ] || { echo "Error: listen EADDRINUSE: address already in use 127.0.0.1:$port" >&2; exit 1; }
                sleep "${FAKE_SHOPIFY_DEV_DELAY:-0}"
                echo "Preparing your theme"
                echo "  ╭─ success ───────────────────────────╮"
                echo "  │  Preview your theme (t)             │"
                echo "  │    • http://127.0.0.1:$port"
                echo "  │  Share your theme preview (p)       │"
                echo "  │    • https://$(val --store)/?preview_theme_id=555"
                echo "  │  Customize your theme at the theme editor (e)"
                echo "  │    • https://$(val --store)/admin/themes/555/editor?hr=$port"
                trap 'exit 0' TERM INT
                while :; do sleep 0.2; done
                ;;
              *)
                echo "Error: onbekend commando: $*" >&2; exit 2;;
            esac
            BASH;
    }
}
