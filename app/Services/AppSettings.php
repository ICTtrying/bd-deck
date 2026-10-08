<?php

namespace App\Services;

use App\Enums\TerminalApp;
use App\Enums\ThemePreference;
use App\Models\Setting;
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

    public function homeDirectory(): string
    {
        return rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root')), '/');
    }

    public function sitesDirectory(): string
    {
        return $this->expand((string) $this->get('sites_directory'));
    }

    public function backupsDirectory(): string
    {
        return $this->sitesDirectory().'/.wpopen-backups';
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

    private function expand(string $path): string
    {
        return Str::startsWith($path, '~') ? $this->homeDirectory().Str::after($path, '~') : $path;
    }
}
