<?php

namespace Tests\Support;

use App\Services\AppSettings;
use App\Services\Offsite\BackupCipher;
use App\Services\Offsite\OffsiteConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Gedeelde opzet voor de tests van cloud-back-ups: een tijdelijke sitesmap en ingevulde instellingen.
 */
class OffsiteFixtures
{
    public const string PASSPHRASE = 'correct-paard-batterij-niet';

    public static function sitesDirectory(): string
    {
        $directory = sys_get_temp_dir().'/bd-deck-offsite-'.uniqid();
        File::ensureDirectoryExists($directory);
        app(AppSettings::class)->update(['sites_directory' => $directory]);

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function configure(FakeS3 $s3, array $overrides = [], string $passphrase = self::PASSPHRASE): void
    {
        app(AppSettings::class)->updateOffsite(array_replace([
            'enabled' => true,
            'provider' => 'backblaze-b2',
            'endpoint' => $s3->endpoint,
            'region' => $s3->region,
            'bucket' => $s3->bucket,
            'access_key_id' => $s3->accessKeyId,
            'secret_access_key' => OffsiteConfig::seal($s3->secretAccessKey),
            'prefix' => 'bd-deck',
            'passphrase' => OffsiteConfig::seal($passphrase),
            'key_id' => app(BackupCipher::class)->keyId($passphrase),
        ], $overrides));
    }

    /**
     * Maakt een back-up-map zoals wpopen die schrijft, standaard ouder dan de rustperiode.
     *
     * @param  array<string, string>  $files
     */
    public static function backup(string $slug, string $id, array $files = ['local-db.sql.gz' => 'database'], ?int $mtime = null): string
    {
        $directory = app(AppSettings::class)->backupsDirectory().'/'.$slug.'/'.$id;
        File::ensureDirectoryExists($directory);

        $kind = substr($id, 16);
        $created = Carbon::createFromFormat('Ymd-His', substr($id, 0, 15))->toIso8601String();
        file_put_contents($directory.'/meta', "kind={$kind}\nsite={$slug}\ncreated={$created}\n");

        foreach ($files as $name => $contents) {
            File::ensureDirectoryExists(dirname($directory.'/'.$name));
            file_put_contents($directory.'/'.$name, $contents);
        }

        $mtime ??= now()->subMinutes(10)->getTimestamp();
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            touch($entry->getPathname(), $mtime);
        }
        touch($directory, $mtime);

        return $directory;
    }
}
