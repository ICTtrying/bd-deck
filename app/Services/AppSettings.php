<?php

namespace App\Services;

use App\Enums\TerminalApp;
use App\Enums\ThemePreference;
use App\Models\Setting;
use App\Services\Offsite\OffsiteConfig;
use Illuminate\Support\Str;

final class AppSettings
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $home = $this->homeDirectory();

        return [
            'sites_directory' => $home.'/wp-sites',
            'script_path' => $home.'/.local/bin/wpopen',
            'editor' => 'code',
            'terminal' => TerminalApp::GnomeTerminal->value,
            'ssh_key_path' => $home.'/.ssh/id_ed25519',
            'locale' => 'nl',
            'theme' => ThemePreference::System->value,
            'auto_login_local' => true,
            'auto_login_live' => true,
            'auto_lock_minutes' => 30,
            'notifications' => true,
            'uptime_enabled' => true,
            'uptime_interval_minutes' => 5,
            'daily_update_check' => true,
            'new_site_packages' => null,
            'go_live_mail_to' => '',
            'offsite_backups' => null,
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= array_replace(
            $this->defaults(),
            Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        foreach (array_intersect_key($values, $this->defaults()) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->values = null;
    }

    /**
     * Vaste code per installatie achter elke nieuwe lokale site: WPMU DEV koppelt een adres
     * als test.ddev.site aan één account wereldwijd, dus algemene namen zijn vaak al bezet.
     */
    public function localSiteSuffix(): string
    {
        $suffix = $this->get('local_site_suffix');

        if (is_string($suffix) && preg_match('/^[a-z0-9]{4}$/', $suffix)) {
            return $suffix;
        }

        $suffix = Str::lower(Str::random(4));
        Setting::query()->updateOrCreate(['key' => 'local_site_suffix'], ['value' => $suffix]);
        $this->values = null;

        return $suffix;
    }

    public function uniqueLocalSiteName(string $name): string
    {
        $suffix = '-'.$this->localSiteSuffix();
        $name = trim($name);

        return Str::endsWith($name, $suffix) ? $name : $name.$suffix;
    }

    /**
     * Opgeslagen keuze uit "Plugins kiezen"; null = nog nooit opgeslagen.
     *
     * @return array{premium: list<string>, plugins: list<string>, extra_plugins: string}|null
     */
    public function newSitePackages(): ?array
    {
        $saved = $this->get('new_site_packages');

        if (! is_array($saved)) {
            return null;
        }

        return [
            'premium' => array_values(array_filter((array) ($saved['premium'] ?? []), 'is_string')),
            'plugins' => array_values(array_filter((array) ($saved['plugins'] ?? []), 'is_string')),
            'extra_plugins' => (string) ($saved['extra_plugins'] ?? ''),
        ];
    }

    public function homeDirectory(): string
    {
        return rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root')), '/');
    }

    public function sitesDirectory(): string
    {
        return $this->expand((string) $this->get('sites_directory'));
    }

    /**
     * Map met de zips van Enfold en de WPMU DEV-plugins; wpopen leest dezelfde map ($HOME/.config/wpsites/premium).
     */
    public function premiumDirectory(): string
    {
        return $this->homeDirectory().'/.config/wpsites/premium';
    }

    public function backupsDirectory(): string
    {
        return $this->sitesDirectory().'/.wpopen-backups';
    }

    public function offsite(): OffsiteConfig
    {
        return OffsiteConfig::fromArray((array) ($this->get('offsite_backups') ?? []));
    }

    /**
     * Voegt samen met wat er al staat: zo blijven opgeslagen geheimen en de sync-status van de wachtrij bewaard.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateOffsite(array $values): void
    {
        $this->values = null;
        $this->update(['offsite_backups' => array_replace((array) ($this->get('offsite_backups') ?? []), $values)]);
    }

    public function scriptPath(): string
    {
        return $this->expand((string) $this->get('script_path'));
    }

    public function isDefaultScriptPath(): bool
    {
        return $this->scriptPath() === $this->defaults()['script_path'];
    }

    public function editor(): string
    {
        return trim((string) $this->get('editor')) ?: 'code';
    }

    public function terminal(): TerminalApp
    {
        return TerminalApp::tryFrom((string) $this->get('terminal')) ?? TerminalApp::GnomeTerminal;
    }

    public function sshKeyPath(): string
    {
        return $this->expand((string) $this->get('ssh_key_path'));
    }

    public function locale(): string
    {
        return in_array($this->get('locale'), ['nl', 'en'], true) ? (string) $this->get('locale') : 'nl';
    }

    public function theme(): ThemePreference
    {
        return ThemePreference::tryFrom((string) $this->get('theme')) ?? ThemePreference::System;
    }

    public function autoLoginLocal(): bool
    {
        return (bool) $this->get('auto_login_local');
    }

    public function autoLoginLive(): bool
    {
        return (bool) $this->get('auto_login_live');
    }

    public function autoLockMinutes(): int
    {
        return max(0, (int) $this->get('auto_lock_minutes'));
    }

    public function notificationsEnabled(): bool
    {
        return (bool) $this->get('notifications');
    }

    /**
     * Keuzes voor het meetinterval in minuten.
     *
     * @return list<int>
     */
    public static function uptimeIntervals(): array
    {
        return [1, 5, 10, 15, 30];
    }

    public function uptimeEnabled(): bool
    {
        return (bool) $this->get('uptime_enabled');
    }

    public function uptimeIntervalMinutes(): int
    {
        $minutes = (int) $this->get('uptime_interval_minutes');

        return in_array($minutes, self::uptimeIntervals(), true) ? $minutes : 5;
    }

    public function dailyUpdateCheck(): bool
    {
        return (bool) $this->get('daily_update_check');
    }

    /**
     * Laatst gebruikte adres voor een testmail vanuit de go-live-checklist.
     */
    public function goLiveMailTo(): string
    {
        return trim((string) $this->get('go_live_mail_to'));
    }

    private function expand(string $path): string
    {
        return Str::startsWith($path, '~') ? $this->homeDirectory().Str::after($path, '~') : $path;
    }
}
