<?php

namespace App\Enums;

enum WpOpenAction: string
{
    case Build = 'build';
    case Rebuild = 'rebuild';
    case PullCode = 'pull-code';
    case PullDatabase = 'pull-database';
    case PullUploads = 'pull-uploads';
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
    case Upgrade = 'upgrade';
    case CacheLocal = 'cache-local';
    case CacheLive = 'cache-live';
    case WpCli = 'wp-cli';
    case Artisan = 'artisan';
    case AddSite = 'add-site';
    case NewSite = 'new-site';
    case Migrate = 'migrate';
    case ChangeDomain = 'change-domain';
    case Multisite = 'multisite';
    case RemoveSite = 'remove-site';
    case Import = 'import';
    case InstallScript = 'install-script';
    case Setup = 'setup';
    case SafeUpdate = 'safe-update';
    case GoLive = 'go-live';
    case Share = 'share';
    case ImportSites = 'import-sites';
    case ShopifyAdd = 'shopify-add';
    case ShopifyDevStart = 'shopify-dev-start';
    case ShopifyDevStop = 'shopify-dev-stop';
    case ShopifyThemes = 'shopify-themes';
    case ShopifyPull = 'shopify-pull';
    case ShopifyCheck = 'shopify-check';
    case ShopifyPush = 'shopify-push';
    case ShopifyPublish = 'shopify-publish';
    case ShopifyRollback = 'shopify-rollback';

    public function label(): string
    {
        return match ($this) {
            self::Build => __('Lokaal bouwen'),
            self::Rebuild => __('Opnieuw migreren'),
            self::PullCode => __('Code ophalen van live'),
            self::PullDatabase => __('Database ophalen van live'),
            self::PullUploads => __('Uploads ophalen van live'),
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
            self::Upgrade => __('Pakketten bijwerken'),
            self::CacheLocal => __('Lokale cache legen'),
            self::CacheLive => __('Live cache legen'),
            self::WpCli => __('WP-CLI-commando'),
            self::Artisan => __('Artisan-commando'),
            self::AddSite => __('Site toevoegen'),
            self::NewSite => __('Lokale site maken'),
            self::Migrate => __('Verhuizen naar nieuwe server'),
            self::ChangeDomain => __('Domein omzetten'),
            self::Multisite => __('Multisite bijwerken'),
            self::RemoveSite => __('Site verwijderen'),
            self::Import => __('Sites importeren'),
            self::InstallScript => __('wpopen installeren'),
            self::Setup => __('Systeemcontrole'),
            self::SafeUpdate => __('Updates veilig doorvoeren'),
            self::GoLive => __('Go-live-checklist'),
            self::Share => __('Lokale site delen'),
            self::ImportSites => __('Sitelijst importeren'),
            self::ShopifyAdd => __('Shopify-project toevoegen'),
            self::ShopifyDevStart => __('Dev server starten'),
            self::ShopifyDevStop => __('Dev server stoppen'),
            self::ShopifyThemes => __('Thema\'s ophalen'),
            self::ShopifyPull => __('Live thema ophalen'),
            self::ShopifyCheck => __('Thema controleren'),
            self::ShopifyPush => __('Naar preview-thema pushen'),
            self::ShopifyPublish => __('Thema publiceren'),
            self::ShopifyRollback => __('Publicatie terugdraaien'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Build, self::Rebuild => 'hammer',
            self::PullCode, self::PullDatabase, self::PullUploads => 'download',
            self::Migrate => 'rocket',
            self::ChangeDomain => 'globe',
            self::Multisite => 'grid',
            self::Push, self::PushPreview => 'upload',
            self::Reset, self::Restore => 'rotate-ccw',
            self::Fix => 'wrench',
            self::Start => 'play',
            self::Stop => 'square',
            self::Backup => 'archive',
            self::Test => 'activity',
            self::Updates, self::Upgrade => 'refresh',
            self::SafeUpdate => 'shield',
            self::GoLive => 'flag',
            self::Share => 'share',
            self::CacheLocal, self::CacheLive => 'zap',
            self::WpCli, self::Artisan => 'terminal',
            self::AddSite, self::Import, self::NewSite, self::ImportSites, self::ShopifyAdd => 'plus',
            self::ShopifyDevStart => 'play',
            self::ShopifyDevStop => 'square',
            self::ShopifyThemes => 'list',
            self::ShopifyPull => 'download',
            self::ShopifyCheck => 'shield',
            self::ShopifyPush => 'upload',
            self::ShopifyPublish => 'rocket',
            self::ShopifyRollback => 'rotate-ccw',
            self::RemoveSite => 'trash',
            self::InstallScript, self::Setup => 'settings',
        };
    }

    /**
     * Acties die de lokale site of live wijzigen mogen per site niet tegelijk lopen. De dev server start en stopt
     * los van de rest (net als delen) en wacht soms minutenlang op een login, dus die krijgt de snelle wachtrij.
     */
    public function locksSite(): bool
    {
        return ! in_array($this, [self::Test, self::Updates, self::PushPreview, self::CacheLive, self::CacheLocal, self::WpCli, self::Artisan, self::GoLive, self::Share, self::ShopifyDevStart, self::ShopifyDevStop, self::ShopifyThemes, self::ShopifyCheck], true);
    }

    /**
     * Korte acties krijgen een eigen worker, zodat ze niet achter een lange bouw wachten.
     */
    public function queue(): string
    {
        return $this->locksSite() ? 'default' : 'quick';
    }

    /**
     * Na deze acties kan de sitelijst zelf veranderd zijn (nieuwe, hernoemde of verwijderde sites).
     */
    public function changesSiteList(): bool
    {
        return in_array($this, [self::AddSite, self::Import, self::InstallScript, self::NewSite, self::Migrate, self::RemoveSite, self::ImportSites, self::ShopifyAdd], true);
    }

    /**
     * Na deze acties is de status van de site veranderd en moet de app hem opnieuw inlezen.
     */
    public function refreshesSite(): bool
    {
        return ! in_array($this, [self::Test, self::Updates, self::PushPreview, self::CacheLive, self::CacheLocal, self::Setup, self::GoLive, self::ShopifyThemes, self::ShopifyCheck], true);
    }
}
