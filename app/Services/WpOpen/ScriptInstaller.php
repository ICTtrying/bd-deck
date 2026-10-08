<?php

namespace App\Services\WpOpen;

use App\Services\AppSettings;

/**
 * De app levert de nieuwste wpopen mee en zet die in ~/.local/bin, zodat terminal en app
 * altijd hetzelfde script gebruiken.
 */
final class ScriptInstaller
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly WpOpen $wpopen,
    ) {}

    public function bundledPath(): string
    {
        return base_path('bin/wpopen');
    }

    public function bundledVersion(): ?string
    {
        return $this->versionOf($this->bundledPath());
    }

    public function installedVersion(): ?string
    {
        return $this->versionOf($this->settings->scriptPath());
    }

    public function isInstalled(): bool
    {
        return is_file($this->settings->scriptPath());
    }

    public function needsInstall(): bool
    {
        // een eigen scriptpad is een bewuste keuze; dat overschrijven we niet
        if (! $this->settings->isDefaultScriptPath()) {
            return false;
        }

        $installed = $this->installedVersion();
        $bundled = $this->bundledVersion();

        return $bundled !== null && ($installed === null || version_compare($installed, $bundled, '<'));
    }

    public function install(): string
    {
        return $this->wpopen->pending()->timeout(30)->run(['bash', $this->bundledPath(), 'install'])->throw()->output();
    }

    public function installIfOutdated(): bool
    {
        if (! $this->needsInstall()) {
            return false;
        }

        $this->install();

        return true;
    }

    private function versionOf(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        // versie 1 kende nog geen `version`-commando, dus lezen in plaats van uitvoeren
        $head = (string) file_get_contents($path, length: 8192);

        return preg_match('/^WPO_VERSION="([0-9.]+)"/m', $head, $matches) ? $matches[1] : '1.0.0';
    }
}
