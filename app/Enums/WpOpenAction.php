<?php

namespace App\Enums;

enum WpOpenAction: string
{
    case Build = 'build';
    case Rebuild = 'rebuild';
    case PullCode = 'pull-code';
    case PullDatabase = 'pull-database';
    case Push = 'push';
    case PushPreview = 'push-preview';
    case Reset = 'reset';
    case Fix = 'fix';
    case Start = 'start';
    case Stop = 'stop';
    case Backup = 'backup';
    case Restore = 'restore';
    case Test = 'test';
    case Updates = 'updates';
    case CacheLocal = 'cache-local';
    case CacheLive = 'cache-live';
    case WpCli = 'wp-cli';
    case AddSite = 'add-site';
    case RemoveSite = 'remove-site';
    case Import = 'import';
    case InstallScript = 'install-script';
    case Setup = 'setup';

    public function label(): string
    {
        return match ($this) {
            self::Build => __('Lokaal bouwen'),
            self::Rebuild => __('Opnieuw migreren'),
            self::PullCode => __('Code ophalen van live'),
            self::PullDatabase => __('Database ophalen van live'),
            self::Push => __('Naar live zetten'),
            self::PushPreview => __('Proefrun naar live'),
            self::Reset => __('Terugzetten naar main'),
            self::Fix => __('Site repareren'),
            self::Start => __('Lokale site starten'),
            self::Stop => __('Lokale site stoppen'),
            self::Backup => __('Back-up maken'),
            self::Restore => __('Back-up terugzetten'),
            self::Test => __('Verbindingstest'),
            self::Updates => __('Updates controleren'),
            self::CacheLocal => __('Lokale cache legen'),
            self::CacheLive => __('Live cache legen'),
            self::WpCli => __('WP-CLI-commando'),
            self::AddSite => __('Site toevoegen'),
            self::RemoveSite => __('Site verwijderen'),
            self::Import => __('Sites importeren'),
            self::InstallScript => __('wpopen installeren'),
            self::Setup => __('Systeemcontrole'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Build, self::Rebuild => 'hammer',
            self::PullCode, self::PullDatabase => 'download',
            self::Push, self::PushPreview => 'upload',
            self::Reset, self::Restore => 'rotate-ccw',
            self::Fix => 'wrench',
            self::Start => 'play',
            self::Stop => 'square',
            self::Backup => 'archive',
            self::Test => 'activity',
            self::Updates => 'refresh',
            self::CacheLocal, self::CacheLive => 'zap',
            self::WpCli => 'terminal',
            self::AddSite, self::Import => 'plus',
            self::RemoveSite => 'trash',
            self::InstallScript, self::Setup => 'settings',
        };
    }

    /**
     * Acties die de lokale site of live wijzigen mogen per site niet tegelijk lopen.
     */
    public function locksSite(): bool
    {
        return ! in_array($this, [self::Test, self::Updates, self::PushPreview, self::CacheLive, self::CacheLocal, self::WpCli], true);
    }

    /**
     * Korte acties krijgen een eigen worker, zodat ze niet achter een lange bouw wachten.
     */
    public function queue(): string
    {
        return $this->locksSite() ? 'default' : 'quick';
    }

    /**
     * Na deze acties is de status van de site veranderd en moet de app hem opnieuw inlezen.
     */
    public function refreshesSite(): bool
    {
        return ! in_array($this, [self::Test, self::Updates, self::PushPreview, self::CacheLive, self::CacheLocal, self::Setup], true);
    }
}
