<?php

namespace App\Services\WpOpen;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\WpOpenAction;
use App\Models\Site;
use InvalidArgumentException;

/**
 * Eén aanroep van wpopen. Alle argumenten worden hier opgebouwd, zodat de app nooit zelf
 * shell-strings samenstelt en tests precies kunnen controleren wat er uitgevoerd wordt.
 */
final readonly class WpOpenCommand
{
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

    public static function start(Site $site): self
    {
        return new self(WpOpenAction::Start, ['start', $site->name], $site);
    }

    public static function stop(Site $site): self
    {
        return new self(WpOpenAction::Stop, ['stop', $site->name], $site);
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

    public static function newSite(string $name, ?string $title = null): self
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException(__('Gebruik alleen letters, cijfers, punt, - en _.'));
        }

        $arguments = ['new', $name];

        if ($title !== null && trim($title) !== '') {
            array_push($arguments, '--title', trim($title));
        }

        return new self(WpOpenAction::NewSite, $arguments);
    }

    /**
     * Lokale site in één keer naar een nieuwe WordPress-installatie: bestanden, uploads en database.
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

    public static function removeSite(Site $site, bool $purgeLocal = false): self
    {
        return new self(WpOpenAction::RemoveSite, array_values(array_filter(['remove', $site->name, '--yes', $purgeLocal ? '--purge' : null])), $site);
    }

    public static function import(): self
    {
        return new self(WpOpenAction::Import, ['import']);
    }

    public static function setup(): self
    {
        return new self(WpOpenAction::Setup, ['setup']);
    }

    public static function install(string $bundledScript): self
    {
        return new self(WpOpenAction::InstallScript, ['install'], script: $bundledScript);
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
