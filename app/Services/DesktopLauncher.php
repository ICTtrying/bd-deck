<?php

namespace App\Services;

use App\Exceptions\DesktopLaunchException;
use App\Support\HostEnvironment;
use Illuminate\Support\Facades\Process;
use Native\Desktop\Facades\Shell;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Opent dingen buiten de app. In het desktopvenster via NativePHP, in de browser
 * (tijdens ontwikkelen) via xdg-open.
 */
final class DesktopLauncher
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly ExecutableFinder $finder,
    ) {}

    public function openUrl(string $url): void
    {
        if (! preg_match('#^https?://#', $url)) {
            throw new DesktopLaunchException(__('Alleen http(s)-adressen kunnen geopend worden.'));
        }

        // op Linux zelf xdg-open starten: Electron geeft de AppImage-omgeving door en dan opent de browser soms niet
        if ($this->isNative() && PHP_OS_FAMILY !== 'Linux') {
            Shell::openExternal($url);

            return;
        }

        $this->detach(['xdg-open', $url]);
    }

    public function openFolder(string $path): void
    {
        $this->assertDirectory($path);

        if ($this->isNative() && PHP_OS_FAMILY !== 'Linux') {
            Shell::openFile($path);

            return;
        }

        $this->detach(['xdg-open', $path]);
    }

    public function openEditor(string $path): void
    {
        $this->assertDirectory($path);
        $this->detach([...$this->splitCommand($this->settings->editor()), $path]);
    }

    public function openTerminal(string $directory): void
    {
        $this->assertDirectory($directory);
        $this->detach($this->settings->terminal()->openIn($directory));
    }

    /**
     * @param  list<string>  $command
     */
    public function runInTerminal(string $directory, array $command, string $title): void
    {
        // venster openhouden na afloop, anders verdwijnt een foutmelding direct
        $keepOpen = implode(' ', array_map('escapeshellarg', $command)).'; echo; read -r -p "'.__('Druk op Enter om te sluiten…').'" _';

        $this->detach($this->settings->terminal()->run($directory, ['bash', '-lc', $keepOpen], $title));
    }

    /**
     * @param  list<string>  $command
     */
    private function detach(array $command): void
    {
        $binary = $command[0];

        if ($this->finder->find($binary, extraDirs: [$this->settings->homeDirectory().'/.local/bin']) === null) {
            throw new DesktopLaunchException(__(':program is niet gevonden. Controleer je instellingen.', ['program' => $binary]));
        }

        // setsid -f: het programma hoort niet bij dit PHP-proces en blijft open als het verzoek klaar is
        $result = Process::env(HostEnvironment::overrides())->timeout(10)->run(['setsid', '-f', ...$command]);

        if (! $result->successful()) {
            throw new DesktopLaunchException(trim($result->errorOutput()) ?: __(':program kon niet gestart worden.', ['program' => $binary]));
        }
    }

    /**
     * @return list<string>
     */
    private function splitCommand(string $command): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($command)) ?: []));
    }

    private function assertDirectory(string $path): void
    {
        if (! is_dir($path)) {
            throw new DesktopLaunchException(__('Map bestaat (nog) niet: :path', ['path' => $path]));
        }
    }

    private function isNative(): bool
    {
        return (bool) config('nativephp-internal.running');
    }
}
