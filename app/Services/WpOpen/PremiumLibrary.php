<?php

namespace App\Services\WpOpen;

use App\Services\AppSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ZipArchive;

/**
 * De zips van betaalde thema's en plugins (Enfold, WPMU DEV) die wpopen in elke nieuwe lokale site zet.
 */
final class PremiumLibrary
{
    public function __construct(private readonly AppSettings $settings) {}

    public function directory(): string
    {
        return $this->settings->premiumDirectory();
    }

    /**
     * Nieuwste eerst; per thema of plugin geldt voor wpopen alleen de nieuwste zip.
     *
     * @return Collection<int, array{file: string, kind: ?string, slug: ?string, size: int, modified: int, active: bool}>
     */
    public function items(): Collection
    {
        $items = collect(File::glob($this->directory().'/*.zip'))
            ->map(function (string $path): array {
                [$kind, $slug] = $this->inspect($path) ?? [null, null];

                return ['file' => basename($path), 'kind' => $kind, 'slug' => $slug, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path), 'active' => false];
            })
            ->sortByDesc('modified')
            ->values();

        $seen = [];

        return $items->map(function (array $item) use (&$seen): array {
            $key = $item['slug'] === null ? null : $item['kind'].':'.$item['slug'];
            $item['active'] = $key !== null && ! isset($seen[$key]);
            $seen[$key ?? ''] = true;

            return $item;
        });
    }

    /**
     * Kopieert zips uit een bestandskiezer naar de map; geeft het aantal gelukte en de namen van de mislukte terug.
     *
     * @param  list<string>  $paths
     * @return array{added: int, rejected: list<string>}
     */
    public function import(array $paths): array
    {
        File::ensureDirectoryExists($this->directory());
        $added = 0;
        $rejected = [];

        foreach ($paths as $path) {
            if (! is_file($path) || ! str_ends_with(mb_strtolower($path), '.zip') || $this->inspect($path) === null) {
                $rejected[] = basename($path);

                continue;
            }

            File::copy($path, $this->directory().'/'.basename($path));
            $added++;
        }

        return ['added' => $added, 'rejected' => $rejected];
    }

    public function delete(string $file): void
    {
        if ($file !== basename($file) || ! str_ends_with($file, '.zip')) {
            throw new InvalidArgumentException(__('Ongeldige bestandsnaam.'));
        }

        File::delete($this->directory().'/'.$file);
    }

    /**
     * Thema of plugin, en de mapnaam ervan. Een ThemeForest-pakket met de echte zip erin wordt doorzocht.
     *
     * @return array{0: string, 1: string}|null
     */
    private function inspect(string $path, int $depth = 0): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_starts_with($name, '__MACOSX')) {
                $names[] = $name;
            }
        }

        $tops = collect($names)->filter(fn (string $name): bool => str_contains($name, '/'))->map(fn (string $name): string => explode('/', $name)[0])->unique()->values();

        if ($tops->count() === 1 && preg_match('/^[A-Za-z0-9._-]+$/', $tops[0])) {
            $top = $tops[0];

            if (in_array($top.'/style.css', $names, true)) {
                $zip->close();

                return ['theme', $top];
            }

            if (collect($names)->contains(fn (string $name): bool => str_starts_with($name, $top.'/') && str_ends_with($name, '.php') && substr_count($name, '/') === 1)) {
                $zip->close();

                return ['plugin', $top];
            }
        }

        if ($depth === 0) {
            foreach ($names as $name) {
                if (! str_contains($name, '/') && str_ends_with(mb_strtolower($name), '.zip')) {
                    $inner = tempnam(sys_get_temp_dir(), 'premium');
                    file_put_contents($inner, $zip->getFromName($name));
                    $found = $this->inspect($inner, 1);
                    @unlink($inner);

                    if ($found !== null) {
                        $zip->close();

                        return $found;
                    }
                }
            }
        }

        $zip->close();

        return null;
    }
}
