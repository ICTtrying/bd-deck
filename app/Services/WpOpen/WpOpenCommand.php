<?php

namespace App\Services\WpOpen;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\WpOpenAction;
use App\Models\Site;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Eén aanroep van wpopen. Alle argumenten worden hier opgebouwd, zodat de app nooit zelf
 * shell-strings samenstelt en tests precies kunnen controleren wat er uitgevoerd wordt.
 */
final readonly class WpOpenCommand
{
    /**
     * Toegestane duur van een deellink in minuten: 1 uur, 4 uur, een werkdag of 24 uur.
     */
    public const array SHARE_DURATIONS = [60, 240, 480, 1440];

    /**
     * @param  list<string>  $arguments
     */
    public function __construct(
        public WpOpenAction $action,
        public array $arguments,
        public ?Site $site = null,
        public ?string $script = null,
    ) {}

    public static function build(Site $site): self
    {
        return new self(WpOpenAction::Build, ['build', $site->name], $site);
    }

    public static function rebuild(Site $site): self
    {
        return new self(WpOpenAction::Rebuild, ['rebuild', $site->name, '--yes'], $site);
    }

    public static function pullCode(Site $site): self
    {
        return new self(WpOpenAction::PullCode, ['pull', $site->name, '--code'], $site);
    }

    public static function pullDatabase(Site $site): self
    {
        return new self(WpOpenAction::PullDatabase, ['pull', $site->name, '--db'], $site);
    }

    public static function pullUploads(Site $site): self
    {
        return new self(WpOpenAction::PullUploads, ['pull', $site->name, '--uploads'], $site);
    }

    public static function push(
        Site $site,
        ?string $message = null,
        bool $database = false,
        bool $uploads = false,
        bool $force = false,
        bool $flushCache = true,
    ): self {
        if ($database && ! $site->mode->hasShell()) {
            throw new InvalidArgumentException(__('Database pushen kan alleen bij SSH-sites.'));
        }

        $arguments = ['push', $site->name, '--yes'];

        if ($message !== null && trim($message) !== '') {
            array_push($arguments, '-m', trim($message));
        }

        foreach (['--db' => $database, '--uploads' => $uploads, '--force' => $force, '--no-flush' => ! $flushCache] as $flag => $enabled) {
            if ($enabled) {
                $arguments[] = $flag;
            }
        }

        return new self(WpOpenAction::Push, $arguments, $site);
    }

    public static function pushPreview(Site $site, bool $database = false): self
    {
        $arguments = ['push', $site->name, '-n'];

        if ($database) {
            $arguments[] = '--db';
        }

        return new self(WpOpenAction::PushPreview, $arguments, $site);
    }

    public static function reset(Site $site, bool $pullFirst = false): self
    {
        return new self(WpOpenAction::Reset, array_values(array_filter(['reset', $site->name, '--yes', $pullFirst ? '--pull' : null])), $site);
    }

    public static function fix(Site $site, bool $disableEnvironmentPlugins = false): self
    {
        return new self(WpOpenAction::Fix, array_values(array_filter(['fix', $site->name, $disableEnvironmentPlugins ? '--plugins' : null])), $site);
    }

    /**
     * Bij een Shopify-project is "starten" de dev server (shopify theme dev).
     */
    public static function start(Site $site): self
    {
        return $site->isShopify()
            ? new self(WpOpenAction::ShopifyDevStart, ['shopify', 'dev', $site->name], $site)
            : new self(WpOpenAction::Start, ['start', $site->name], $site);
    }

    public static function stop(Site $site): self
    {
        return $site->isShopify()
            ? new self(WpOpenAction::ShopifyDevStop, ['shopify', 'stop', $site->name], $site)
            : new self(WpOpenAction::Stop, ['stop', $site->name], $site);
    }

    /**
     * Lokale site tijdelijk delen via een openbare link (ddev share met cloudflared).
     */
    public static function share(Site $site, int $minutes): self
    {
        if (! $site->isBuilt()) {
            throw new InvalidArgumentException(__(':site is nog niet lokaal gebouwd. Kies eerst "Lokaal bouwen".', ['site' => $site->name]));
        }

        if ($site->isMultisite()) {
            throw new InvalidArgumentException(__('Een multisite kan niet gedeeld worden: elke subsite heeft een eigen adres.'));
        }

        if (! in_array($minutes, self::SHARE_DURATIONS, true)) {
            throw new InvalidArgumentException(__('Kies hoe lang de link moet werken.'));
        }

        return new self(WpOpenAction::Share, ['share', $site->name, '--minutes', (string) $minutes], $site);
    }

    public static function shareStop(Site $site): self
    {
        return new self(WpOpenAction::Share, ['share', $site->name, '--stop'], $site);
    }

    public static function backup(Site $site, bool $local = true, bool $liveDatabase = false, bool $liveFiles = false): self
    {
        $flags = array_keys(array_filter(['--local' => $local, '--live-db' => $liveDatabase, '--live-files' => $liveFiles]));

        if ($flags === []) {
            throw new InvalidArgumentException(__('Kies minstens één onderdeel voor de back-up.'));
        }

        return new self(WpOpenAction::Backup, ['backup', $site->name, ...$flags], $site);
    }

    /**
     * @param  list<string>  $parts  local-db, live-db en/of live-files
     */
    public static function restore(Site $site, string $backupId, array $parts): self
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $backupId)) {
            throw new InvalidArgumentException(__('Ongeldige back-up.'));
        }

        $allowed = array_values(array_intersect(['local-db', 'live-db', 'live-files'], $parts));

        if ($allowed === []) {
            throw new InvalidArgumentException(__('Kies wat je wilt terugzetten.'));
        }

        return new self(WpOpenAction::Restore, ['restore', $site->name, $backupId, '--yes', ...array_map(fn (string $part): string => '--'.$part, $allowed)], $site);
    }

    public static function test(Site $site): self
    {
        return new self(WpOpenAction::Test, ['test', $site->name, '--json'], $site);
    }

    public static function updates(Site $site): self
    {
        return new self(WpOpenAction::Updates, ['updates', $site->name, '--json'], $site);
    }

    /**
     * WordPress: live ophalen, lokaal bijwerken, pagina's controleren en pas daarna live zetten met back-up.
     * Zonder $only gaan alle beschikbare updates mee; met $live = false wordt alleen lokaal getest.
     *
     * @param  list<string>  $only  'core', 'plugin:slug' of 'theme:slug'
     */
    public static function safeUpdate(Site $site, array $only = [], bool $live = true, bool $force = false): self
    {
        if ($site->isLaravel()) {
            throw new InvalidArgumentException(__('Veilig bijwerken kan alleen bij WordPress-sites; gebruik bij Laravel Pakketten bijwerken.'));
        }

        if ($site->isLocalOnly()) {
            throw new InvalidArgumentException(__('Deze site heeft nog geen live-server.'));
        }

        $arguments = ['safe-update', $site->name];

        foreach (array_unique($only) as $item) {
            if (! preg_match('/^(core|(plugin|theme):[A-Za-z0-9._-]+)$/', $item)) {
                throw new InvalidArgumentException(__('Ongeldige update: :item', ['item' => $item]));
            }

            array_push($arguments, '--only', $item);
        }

        if (! $live) {
            $arguments[] = '--no-live';
        }

        if ($force) {
            $arguments[] = '--force';
        }

        $arguments[] = '--yes';

        return new self(WpOpenAction::SafeUpdate, $arguments, $site);
    }

    /**
     * Laravel: back-up, Composer- en npm-pakketten bijwerken en committen op dev; met $live ook meteen live zetten.
     * Met $major gaan ook nieuwe hoofdversies mee (bv. Laravel 12 naar 13), anders blijft alles binnen composer.json en package.json.
     * Met $packages ('composer:naam' of 'npm:naam') worden alleen die pakketten bijgewerkt, ook bij een hoofdversie.
     *
     * @param  list<string>  $packages
     */
    public static function upgrade(Site $site, bool $live = false, bool $major = false, array $packages = []): self
    {
        if (! $site->isLaravel()) {
            throw new InvalidArgumentException(__('Pakketten bijwerken kan alleen bij Laravel-sites.'));
        }

        foreach ($packages as $package) {
            if (! preg_match('#^(composer|npm):[A-Za-z0-9@/._-]+$#', $package)) {
                throw new InvalidArgumentException(__('Ongeldig pakket.'));
            }
        }

        return new self(WpOpenAction::Upgrade, array_values(array_filter(['upgrade', $site->name, $major ? '--major' : null, ...collect($packages)->flatMap(fn (string $package): array => ['--package', $package])->all(), $live ? '--live' : null, $live ? '--yes' : null])), $site);
    }

    public static function cache(Site $site, bool $live): self
    {
        return $live
            ? new self(WpOpenAction::CacheLive, ['cache', $site->name, '--live'], $site)
            : new self(WpOpenAction::CacheLocal, ['cache', $site->name], $site);
    }

    /**
     * @param  list<string>  $wpArguments
     */
    public static function wpCli(Site $site, array $wpArguments, bool $live = false): self
    {
        if ($wpArguments === []) {
            throw new InvalidArgumentException(__('Geef een WP-CLI-commando op.'));
        }

        if ($live && ! $site->mode->hasShell()) {
            throw new InvalidArgumentException(__('WP-CLI op live kan alleen bij SSH-sites.'));
        }

        // "wp" voor het commando is overbodig; mensen typen het uit gewoonte toch
        if ($wpArguments[0] === 'wp') {
            array_shift($wpArguments);
        }

        return new self(WpOpenAction::WpCli, ['wp', $site->name, ...($live ? ['--live'] : []), '--', ...$wpArguments], $site);
    }

    /**
     * @param  list<string>  $artisanArguments
     */
    public static function artisan(Site $site, array $artisanArguments, bool $live = false): self
    {
        if (! $site->isLaravel()) {
            throw new InvalidArgumentException(__('Artisan kan alleen bij Laravel-sites.'));
        }

        if ($live && ! $site->mode->hasShell()) {
            throw new InvalidArgumentException(__('Artisan op live kan alleen bij SSH-sites.'));
        }

        // "php artisan" ervoor is overbodig; mensen typen het uit gewoonte toch
        if (array_slice($artisanArguments, 0, 2) === ['php', 'artisan']) {
            $artisanArguments = array_slice($artisanArguments, 2);
        } elseif (($artisanArguments[0] ?? null) === 'artisan') {
            array_shift($artisanArguments);
        }

        if ($artisanArguments === []) {
            throw new InvalidArgumentException(__('Geef een artisan-commando op.'));
        }

        return new self(WpOpenAction::Artisan, ['artisan', $site->name, ...($live ? ['--live'] : []), '--', ...$artisanArguments], $site);
    }

    public static function addSite(
        string $name,
        string $target,
        string $sshOptions = '',
        ?string $remotePath = null,
        ?SiteMode $mode = null,
        ?SiteProvider $provider = null,
        ?string $liveUrl = null,
    ): self {
        $arguments = ['add', $name, ...self::splitOptions($sshOptions), $target];

        if ($remotePath !== null && $remotePath !== '') {
            array_push($arguments, '--remote', $remotePath, '--mode', ($mode ?? SiteMode::Ssh)->value);
        }

        if ($provider !== null) {
            array_push($arguments, '--provider', $provider->value);
        }

        if ($liveUrl !== null && $liveUrl !== '') {
            array_push($arguments, '--url', $liveUrl);
        }

        return new self(WpOpenAction::AddSite, $arguments);
    }

    /**
     * @param  list<string>|null  $premium  zips uit de premium-map als 'theme:slug' of 'plugin:slug'; null = alles
     * @param  list<string>  $plugins  gratis plugins van wordpress.org
     */
    public static function newSite(
        string $name,
        ?string $title = null,
        bool $multisite = false,
        ?array $premium = null,
        array $plugins = [],
    ): self {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException(__('Gebruik alleen letters, cijfers, punt, - en _.'));
        }

        $arguments = ['new', $name];

        if ($title !== null && trim($title) !== '') {
            array_push($arguments, '--title', trim($title));
        }

        if ($multisite) {
            $arguments[] = '--multisite';
        }

        if ($premium === []) {
            $arguments[] = '--no-premium';
        }

        foreach ($premium ?? [] as $package) {
            if (! preg_match('/^(theme|plugin):[A-Za-z0-9._-]+$/', $package)) {
                throw new InvalidArgumentException(__('Ongeldige keuze uit de premium-map.'));
            }

            array_push($arguments, '--premium', $package);
        }

        foreach (array_unique($plugins) as $slug) {
            if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
                throw new InvalidArgumentException(__('Ongeldige naam: :slug. Gebruik de naam uit de wordpress.org-link.', ['slug' => $slug]));
            }

            array_push($arguments, '--plugin', $slug);
        }

        return new self(WpOpenAction::NewSite, $arguments);
    }

    /**
     * Lokale site in één keer naar een nieuwe WordPress-installatie: bestanden, uploads en database.
     *
     * @param  array<string, string>  $subsiteHosts  multisite: lokaal adres → live-adres; leeg = wpopen kiest
     */
    public static function migrate(
        Site $site,
        string $target,
        string $liveUrl,
        string $sshOptions = '',
        ?string $remotePath = null,
        ?SiteMode $mode = null,
        ?SiteProvider $provider = null,
        bool $keepOld = true,
        bool $pullUploads = true,
        array $subsiteHosts = [],
    ): self {
        if (! preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/', $target)) {
            throw new InvalidArgumentException(__('Server moet de vorm gebruiker@host hebben.'));
        }

        if (! preg_match('#^https?://[A-Za-z0-9.-]+/?$#', $liveUrl)) {
            throw new InvalidArgumentException(__('Gebruik een volledig adres zonder pad, bijvoorbeeld https://klant.nl'));
        }

        $arguments = ['migrate', $site->name, ...self::splitOptions($sshOptions), $target, '--url', rtrim($liveUrl, '/'), '--yes'];

        if ($remotePath !== null && $remotePath !== '') {
            array_push($arguments, '--remote', $remotePath);
        }

        if ($mode !== null && $mode !== SiteMode::Local) {
            array_push($arguments, '--mode', $mode->value);
        }

        if ($provider !== null) {
            array_push($arguments, '--provider', $provider->value);
        }

        if ($keepOld && ! $site->isLocalOnly()) {
            $arguments[] = '--keep-old';
        }

        if (! $pullUploads) {
            $arguments[] = '--no-uploads';
        }

        foreach (array_filter($subsiteHosts) as $localHost => $liveHost) {
            if (! preg_match('/^[a-z0-9.-]+$/', (string) $localHost) || ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $liveHost)) {
                throw new InvalidArgumentException(__('Ongeldig live-adres voor :host.', ['host' => $localHost]));
            }

            array_push($arguments, '--map', $localHost.'='.$liveHost);
        }

        return new self(WpOpenAction::Migrate, $arguments, $site);
    }

    public static function changeDomain(Site $site, string $liveUrl): self
    {
        if (! preg_match('#^https?://[A-Za-z0-9.-]+/?$#', $liveUrl)) {
            throw new InvalidArgumentException(__('Gebruik een volledig adres zonder pad, bijvoorbeeld https://klant.nl'));
        }

        if (! $site->mode->hasShell()) {
            throw new InvalidArgumentException(__('Domein omzetten kan alleen bij SSH-sites.'));
        }

        return new self(WpOpenAction::ChangeDomain, ['domain', $site->name, rtrim($liveUrl, '/'), '--yes'], $site);
    }

    /**
     * Go-live-checklist na verhuizen of domein omzetten; met $mailTo wordt er echt een testmail verstuurd,
     * met $fix wordt eerst dat onderdeel op live rechtgezet en daarna alles opnieuw gecontroleerd.
     */
    public static function goLive(Site $site, ?string $mailTo = null, ?string $fix = null): self
    {
        if ($site->isLocalOnly()) {
            throw new InvalidArgumentException(__('Deze site staat nog niet live.'));
        }

        $arguments = ['golive', $site->name, '--json'];

        if ($mailTo !== null && $mailTo !== '') {
            // het adres gaat letterlijk naar PHP op live: alleen gewone tekens
            if ($site->isLaravel() || filter_var($mailTo, FILTER_VALIDATE_EMAIL) === false || ! preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $mailTo)) {
                throw new InvalidArgumentException(__('Vul een geldig e-mailadres in.'));
            }

            array_push($arguments, '--mail-to', $mailTo);
        }

        if ($fix !== null) {
            if (! in_array($fix, ['search', 'debug', 'local-urls'], true) || $site->isLaravel()) {
                throw new InvalidArgumentException(__('Onbekende actie.'));
            }

            if ($fix !== 'search' && ! $site->mode->hasShell()) {
                throw new InvalidArgumentException(__('Dit kan alleen bij SSH-sites.'));
            }

            array_push($arguments, '--fix', $fix);
        }

        return new self(WpOpenAction::GoLive, $arguments, $site);
    }

    public static function convertToMultisite(Site $site): self
    {
        return new self(WpOpenAction::Multisite, ['multisite', $site->name], $site);
    }

    /**
     * Een los woord wordt een subdomein van de lokale site; met een punt is het een eigen domein.
     */
    public static function addSubsite(Site $site, string $address): self
    {
        $address = Str::lower(trim($address));

        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $address)) {
            throw new InvalidArgumentException(__('Gebruik een naam zoals winkel of een domein zoals klant2.test.'));
        }

        return new self(WpOpenAction::Multisite, ['multisite', $site->name, '--add', $address], $site);
    }

    public static function removeSubsite(Site $site, int $subsiteId): self
    {
        // 1 is de hoofdsite van het netwerk
        if ($subsiteId < 2) {
            throw new InvalidArgumentException(__('De hoofdsite kan niet weg.'));
        }

        return new self(WpOpenAction::Multisite, ['multisite', $site->name, '--remove', (string) $subsiteId], $site);
    }

    public static function removeSite(Site $site, bool $purgeLocal = false): self
    {
        // de themamap van een Shopify-project is de repo van de klant: die gooit BD Deck nooit weg
        $purge = $purgeLocal && ! $site->isShopify();

        return new self(WpOpenAction::RemoveSite, array_values(array_filter(['remove', $site->name, '--yes', $purge ? '--purge' : null])), $site);
    }

    public static function import(): self
    {
        return new self(WpOpenAction::Import, ['import']);
    }

    /**
     * Sitelijst van een collega; $only zijn de sites die de gebruiker in de voorvertoning heeft aangevinkt.
     *
     * @param  list<string>  $only
     */
    public static function importSites(string $path, array $only, bool $update = false): self
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidArgumentException(__('Ongeldig bestand.'));
        }

        if ($only === []) {
            throw new InvalidArgumentException(__('Kies minstens één site om te importeren.'));
        }

        foreach ($only as $name) {
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                throw new InvalidArgumentException(__('Ongeldige sitenaam: :name', ['name' => $name]));
            }
        }

        $arguments = ['import-sites', $path, ...collect($only)->flatMap(fn (string $name): array => ['--only', $name])->all()];

        if ($update) {
            $arguments[] = '--update';
        }

        return new self(WpOpenAction::ImportSites, [...$arguments, '--json']);
    }

    public static function setup(): self
    {
        return new self(WpOpenAction::Setup, ['setup']);
    }

    public static function install(string $bundledScript): self
    {
        return new self(WpOpenAction::InstallScript, ['install'], script: $bundledScript);
    }

    /**
     * De winkel als naam.myshopify.com; ook een adres of alleen het voorvoegsel is goed. Een eigen domein niet:
     * de Shopify CLI en alle previewlinks werken met de myshopify.com-naam.
     */
    public static function normalizeStore(string $store): string
    {
        $store = Str::of($store)->trim()->lower()->after('://')->before('/')->toString();

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $store) === 1) {
            $store .= '.myshopify.com';
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $store) !== 1) {
            throw new InvalidArgumentException(__('Gebruik de .myshopify.com-naam van de winkel (bijvoorbeeld winkel.myshopify.com), niet het eigen domein.'));
        }

        return $store;
    }

    /**
     * @param  list<string>|null  $ignore  null = standaard (editorbestanden van de klant), [] = geen bescherming
     * @return list<string>
     */
    private static function shopifyOptions(?string $path, ?int $port, ?string $liveUrl, ?array $ignore, ?string $host): array
    {
        $arguments = [];

        if ($path !== null) {
            if (! str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/[|\x00-\x1f]/', $path) === 1) {
                throw new InvalidArgumentException(__('Gebruik een volledig pad naar de themamap.'));
            }

            array_push($arguments, '--path', $path);
        }

        if ($port !== null) {
            if ($port < 1024 || $port > 65535) {
                throw new InvalidArgumentException(__('De poort moet tussen 1024 en 65535 liggen.'));
            }

            array_push($arguments, '--port', (string) $port);
        }

        if ($liveUrl !== null) {
            if ($liveUrl !== '' && preg_match('#^https?://[A-Za-z0-9.-]+/?$#', $liveUrl) !== 1) {
                throw new InvalidArgumentException(__('Gebruik een volledig adres zonder pad, bijvoorbeeld https://klant.nl'));
            }

            array_push($arguments, '--url', $liveUrl);
        }

        if ($ignore !== null) {
            foreach ($ignore === [] ? ['none'] : $ignore as $pattern) {
                if ($pattern !== 'none' && (preg_match('#^[A-Za-z0-9._*?/-]+$#', $pattern) !== 1 || str_starts_with($pattern, '/') || str_contains($pattern, '..'))) {
                    throw new InvalidArgumentException(__('Ongeldig patroon: :pattern', ['pattern' => $pattern]));
                }

                array_push($arguments, '--ignore', $pattern);
            }
        }

        if ($host !== null) {
            if (! in_array($host, ['127.0.0.1', '0.0.0.0'], true)) {
                throw new InvalidArgumentException(__('Kies 127.0.0.1 (alleen deze computer) of 0.0.0.0 (hele netwerk).'));
            }

            array_push($arguments, '--host', $host);
        }

        return $arguments;
    }

    /**
     * Shopify-project koppelen aan een bestaande themamap, of (met $create) een nieuwe map vanaf het live thema maken.
     *
     * @param  list<string>|null  $ignore
     */
    public static function shopifyAdd(
        string $name,
        string $store,
        ?string $path = null,
        bool $create = false,
        ?int $port = null,
        ?string $liveUrl = null,
        ?array $ignore = null,
        bool $themeEditorSync = false,
        string $host = '127.0.0.1',
    ): self {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Gebruik alleen letters, cijfers, punt, - en _.'));
        }

        $arguments = [
            'shopify', 'add', $name, '--store', self::normalizeStore($store),
            ...self::shopifyOptions($path, $port, $liveUrl !== null && $liveUrl !== '' ? $liveUrl : null, $ignore, $host === '127.0.0.1' ? null : $host),
        ];

        if ($create) {
            $arguments[] = '--create';
        }

        if ($themeEditorSync) {
            $arguments[] = '--sync';
        }

        return new self(WpOpenAction::ShopifyAdd, $arguments);
    }

    /**
     * Instellingen van een Shopify-project; alleen wat is opgegeven verandert. $liveUrl '' haalt het eigen domein weg.
     *
     * @param  list<string>|null  $ignore
     * @return list<string>
     */
    public static function shopifyUpdate(
        Site $site,
        ?string $store = null,
        ?string $path = null,
        ?int $port = null,
        ?string $liveUrl = null,
        ?array $ignore = null,
        ?bool $themeEditorSync = null,
        ?string $host = null,
    ): array {
        if (! $site->isShopify()) {
            throw new InvalidArgumentException(__('Dit is geen Shopify-project.'));
        }

        $arguments = ['shopify', 'update', $site->name, ...self::shopifyOptions($path, $port, $liveUrl, $ignore, $host)];

        if ($store !== null) {
            array_push($arguments, '--store', self::normalizeStore($store));
        }

        if ($themeEditorSync !== null) {
            array_push($arguments, '--sync', $themeEditorSync ? '1' : '0');
        }

        return $arguments;
    }

    private static function shopifyAction(Site $site, WpOpenAction $action, string $command, string ...$extra): self
    {
        if (! $site->isShopify()) {
            throw new InvalidArgumentException(__('Dit is geen Shopify-project.'));
        }

        return new self($action, ['shopify', $command, $site->name, ...$extra], $site);
    }

    public static function shopifyThemes(Site $site): self
    {
        return self::shopifyAction($site, WpOpenAction::ShopifyThemes, 'themes', '--json');
    }

    /**
     * Standaard alleen wat de klant in de editor aanpast; met $all het hele live thema.
     */
    public static function shopifyPull(Site $site, bool $all = false): self
    {
        return $all
            ? self::shopifyAction($site, WpOpenAction::ShopifyPull, 'pull', '--all')
            : self::shopifyAction($site, WpOpenAction::ShopifyPull, 'pull');
    }

    public static function shopifyCheck(Site $site): self
    {
        return self::shopifyAction($site, WpOpenAction::ShopifyCheck, 'check', '--json');
    }

    /**
     * Nooit naar het live thema: de CLI maakt een nieuw niet-gepubliceerd thema, of werkt een bestaand preview-thema bij.
     */
    public static function shopifyPush(Site $site, ?string $themeName = null, bool $pullFirst = true, bool $check = true, bool $allowCheckErrors = false): self
    {
        $themeName = $themeName === null ? null : trim($themeName);

        if ($themeName !== null && $themeName !== '' && (mb_strlen($themeName) > 60 || preg_match('/[|\x00-\x1f\x7f]/u', $themeName) === 1)) {
            throw new InvalidArgumentException(__('Een themanaam mag maximaal 60 tekens zijn en geen | of enters bevatten.'));
        }

        $arguments = $themeName === null || $themeName === '' ? [] : ['--theme', $themeName];

        foreach (['--no-pull' => ! $pullFirst, '--no-check' => ! $check, '--allow-check-errors' => $allowCheckErrors] as $flag => $enabled) {
            if ($enabled) {
                $arguments[] = $flag;
            }
        }

        return self::shopifyAction($site, WpOpenAction::ShopifyPush, 'push', ...[...$arguments, '--yes']);
    }

    /**
     * Een bestaand niet-gepubliceerd thema live zetten. Met $syncEditorFiles neemt het script eerst de bestanden over die
     * de klant op live in de editor aanpaste, zodat die niet verloren gaan.
     */
    public static function shopifyPublish(Site $site, int $themeId, bool $syncEditorFiles = true): self
    {
        if ($themeId < 1) {
            throw new InvalidArgumentException(__('Kies het thema dat live moet.'));
        }

        return self::shopifyAction($site, WpOpenAction::ShopifyPublish, 'publish', '--theme', (string) $themeId, $syncEditorFiles ? '--sync-editor-files' : '--keep-editor-files', '--yes');
    }

    public static function shopifyRollback(Site $site): self
    {
        return self::shopifyAction($site, WpOpenAction::ShopifyRollback, 'rollback', '--yes');
    }

    public function label(): string
    {
        return $this->action->label();
    }

    /**
     * SSH-opties komen als losse argumenten binnen, net als op de commandoregel.
     *
     * @return list<string>
     */
    public static function splitOptions(string $options): array
    {
        $options = trim($options);

        if ($options === '') {
            return [];
        }

        if (! preg_match('/^(-[A-Za-z]( +[^\s|;&`$<>]+)?)( +-[A-Za-z]( +[^\s|;&`$<>]+)?)*$/', $options)) {
            throw new InvalidArgumentException(__('SSH-opties hebben een ongeldig formaat, bijvoorbeeld: -p 65002'));
        }

        return preg_split('/ +/', $options) ?: [];
    }
}
