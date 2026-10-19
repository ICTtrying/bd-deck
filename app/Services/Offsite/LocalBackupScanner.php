<?php

namespace App\Services\Offsite;

use App\Services\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Leest de back-up-mappen van wpopen zonder het script aan te roepen: de sync loopt voor alle sites tegelijk.
 */
final readonly class LocalBackupScanner
{
    /**
     * wpopen schrijft een back-up in stappen; pas na deze rust is hij af.
     */
    public const int SETTLE_SECONDS = 120;

    /**
     * Map binnen de back-ups voor tijdelijke bestanden; de punt houdt hem buiten de lijst van wpopen.
     */
    public const string WORK_DIRECTORY = '.offsite-tmp';

    public function __construct(private AppSettings $settings) {}

    /**
     * @return Collection<int, LocalBackup>
     */
    public function all(): Collection
    {
        $root = $this->settings->backupsDirectory();
        $backups = collect();

        foreach (glob($root.'/*/*/meta') ?: [] as $meta) {
            $path = dirname($meta);
            $id = basename($path);
            $slug = basename(dirname($path));

            if (! preg_match('/^[a-z0-9-]+$/', $slug) || ! preg_match('/^[A-Za-z0-9._-]+$/', $id) || str_starts_with($id, '.')) {
                continue;
            }

            $values = $this->readMeta($meta);

            $backups->push(new LocalBackup(
                slug: $slug,
                id: $id,
                path: $path,
                siteName: $values['site'] ?? $slug,
                kind: $values['kind'] ?? (Str::after(Str::after($id, '-'), '-') ?: ''),
                createdAt: $this->createdAt($id, $values['created'] ?? null, (int) filemtime($meta)),
                lastModified: $this->lastModified($path),
            ));
        }

        return $backups->sortByDesc(fn (LocalBackup $backup): string => $backup->createdAt->format('YmdHis').$backup->id)->values();
    }

    /**
     * Back-ups waar wpopen klaar mee is.
     *
     * @return Collection<int, LocalBackup>
     */
    public function complete(): Collection
    {
        $settled = now()->subSeconds(self::SETTLE_SECONDS)->getTimestamp();

        return $this->all()->filter(fn (LocalBackup $backup): bool => $backup->lastModified <= $settled)->values();
    }

    public function workDirectory(): string
    {
        $directory = $this->settings->backupsDirectory().'/'.self::WORK_DIRECTORY;

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    /**
     * @return array<string, string>
     */
    private function readMeta(string $file): array
    {
        $values = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] ??= trim($value);
            }
        }

        return $values;
    }

    private function createdAt(string $id, ?string $created, int $fallback): CarbonImmutable
    {
        if ($created !== null && $created !== '') {
            try {
                return CarbonImmutable::parse($created);
            } catch (Throwable) {
                // val terug op de naam van de map
            }
        }

        if (preg_match('/^\d{8}-\d{6}/', $id) === 1) {
            return CarbonImmutable::createFromFormat('Ymd-His', substr($id, 0, 15)) ?: CarbonImmutable::createFromTimestamp($fallback);
        }

        return CarbonImmutable::createFromTimestamp($fallback);
    }

    private function lastModified(string $path): int
    {
        $latest = (int) filemtime($path);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file) {
            // lstat: een kapotte symlink in live-files mag de scan niet laten vallen
            $latest = max($latest, (int) (@lstat($file->getPathname())['mtime'] ?? 0));
        }

        return $latest;
    }
}
