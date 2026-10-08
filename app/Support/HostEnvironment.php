<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Een AppImage zet bibliotheek- en datapaden naar zijn eigen tijdelijke map. Programma's die
 * de app start (browser, editor, ddev) erven die en starten dan niet of stilletjes verkeerd.
 */
final class HostEnvironment
{
    private const array APPIMAGE_ONLY = ['APPDIR', 'APPIMAGE', 'ARGV0', 'OWD', 'GSETTINGS_SCHEMA_DIR'];

    private const array PATH_LISTS = ['PATH', 'LD_LIBRARY_PATH', 'XDG_DATA_DIRS', 'GTK_PATH', 'GIO_MODULE_DIR', 'QT_PLUGIN_PATH', 'PYTHONPATH', 'PERLLIB'];

    /**
     * Overschrijvingen voor een proces: false haalt een variabele weg (Symfony Process).
     *
     * @param  array<string, string|false>|null  $environment  standaard de huidige omgeving
     * @return array<string, string|false>
     */
    public static function overrides(?array $environment = null): array
    {
        $environment ??= getenv();
        $appDirectory = $environment['APPDIR'] ?? null;

        if (! is_string($appDirectory) || $appDirectory === '') {
            return [];
        }

        $overrides = array_fill_keys(self::APPIMAGE_ONLY, false);

        foreach (self::PATH_LISTS as $name) {
            if (! isset($environment[$name]) || ! is_string($environment[$name])) {
                continue;
            }

            $kept = collect(explode(':', $environment[$name]))
                ->reject(fn (string $entry): bool => $entry === '' || $entry === '.' || Str::startsWith($entry, ['./', $appDirectory]))
                ->implode(':');

            $overrides[$name] = $kept === '' ? false : $kept;
        }

        return $overrides;
    }
}
