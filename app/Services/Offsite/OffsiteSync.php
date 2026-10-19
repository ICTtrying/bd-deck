<?php

namespace App\Services\Offsite;

use App\Enums\OffsiteStatus;
use App\Exceptions\OffsiteException;
use App\Models\OffsiteBackup;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zet lokale back-ups in de cloud, past de bewaarregels toe en ruimt desgewenst lokaal op.
 */
final readonly class OffsiteSync
{
    public function __construct(
        private AppSettings $settings,
        private LocalBackupScanner $scanner,
        private S3StorageFactory $storageFactory,
        private BackupArchive $archive,
        private BackupCipher $cipher,
        private DesktopNotifier $notifier,
    ) {}

    /**
     * @return array{uploaded: int, failed: int, pruned: int, cleaned: int}
     */
    public function run(): array
    {
        $result = ['uploaded' => 0, 'failed' => 0, 'pruned' => 0, 'cleaned' => 0];
        $config = $this->settings->offsite();

        if (! $config->isActive()) {
            return $result;
        }

        $storage = $this->storageFactory->make($config);

        // de sync draait nooit dubbel, dus 'uploading' bij de start is een afgebroken vorige run
        OffsiteBackup::query()->where('status', OffsiteStatus::Uploading)->update(['status' => OffsiteStatus::Pending]);

        $local = $this->scanner->complete();
        $errors = [];

        foreach ($this->toUpload($local, $config) as $backup) {
            $error = $this->upload($backup, $storage, $config);

            if ($error === null) {
                $result['uploaded']++;
            } else {
                $result['failed']++;
                $errors[] = $error;
            }
        }

        $result['pruned'] = $this->applyRetention($storage, $config);

        if ($config->cleanupLocal) {
            $result['cleaned'] = $this->cleanUpLocal($local, $storage, $config);
        }

        $this->finish($config, $result['failed'], $errors[0] ?? null);

        return $result;
    }

    /**
     * Schrijft, leest en verwijdert een klein versleuteld testbestand.
     *
     * @throws OffsiteException
     */
    public function testConnection(OffsiteConfig $config): void
    {
        $storage = $this->storageFactory->make($config);
        $passphrase = $config->passphrase ?? Str::random(32);
        $key = $config->prefix.'/.bd-deck-test-'.Str::lower(Str::random(12));
        $probe = Str::random(32);

        $storage->putString($key, $this->cipher->encryptString($probe, $passphrase));

        try {
            if ($this->cipher->decryptString($storage->getString($key), $passphrase) !== $probe) {
                throw new OffsiteException(__('Het testbestand kwam anders terug dan het verstuurd is.'));
            }
        } catch (Throwable $exception) {
            rescue(fn () => $storage->delete($key), report: false);

            throw $exception;
        }

        $storage->delete($key);
    }

    /**
     * Zoekt in de bucket naar back-ups van een site, bijvoorbeeld op een nieuwe laptop.
     *
     * @return int aantal nieuw gevonden back-ups
     */
    public function discover(string $slug, string $siteName): int
    {
        $config = $this->settings->offsite();

        if (! $config->isComplete()) {
            throw new OffsiteException(__('Stel eerst de cloudopslag in bij Instellingen.'));
        }

        $storage = $this->storageFactory->make($config);
        $objects = collect($storage->list($config->sitePrefix($slug)))->keyBy('key');
        $rows = OffsiteBackup::query()->forSite($slug)->get()->keyBy('backup_id');
        $found = 0;

        foreach ($objects as $key => $object) {
            if (preg_match('#/([A-Za-z0-9._-]+)\.tar\.enc$#', $key, $matches) !== 1) {
                continue;
            }

            $id = $matches[1];
            $row = $rows->get($id);

            if ($row !== null && ($row->status === OffsiteStatus::Uploaded || $row->status->isBusy())) {
                continue;
            }

            $metadata = $storage->head($key)['metadata'] ?? [];

            OffsiteBackup::query()->updateOrCreate(['site_slug' => $slug, 'backup_id' => $id], [
                'site_name' => ($metadata['site'] ?? '') ?: $siteName,
                'kind' => ($metadata['kind'] ?? '') ?: (Str::after(Str::after($id, '-'), '-') ?: null),
                'backup_created_at' => $this->createdAt($id, $metadata['created'] ?? null, $object['last_modified']),
                'object_key' => $key,
                'size' => $object['size'],
                'key_id' => ($metadata['key-id'] ?? '') ?: null,
                'status' => OffsiteStatus::Uploaded,
                'error' => null,
                'uploaded_at' => $object['last_modified'] ?? now(),
            ]);

            $found++;
        }

        // wat volgens de app in de cloud staat maar daar niet meer is (handmatig weggehaald)
        OffsiteBackup::query()->forSite($slug)->inCloud()
            ->whereNotIn('object_key', $objects->keys()->all())
            ->update(['status' => OffsiteStatus::Deleted]);

        return $found;
    }

    /**
     * Nieuwe en eerder mislukte back-ups, behalve wat de bewaarregels toch meteen weer zouden weggooien.
     *
     * @param  Collection<int, LocalBackup>  $local
     * @return Collection<int, LocalBackup>
     */
    private function toUpload(Collection $local, OffsiteConfig $config): Collection
    {
        $rows = OffsiteBackup::query()->whereIn('site_slug', $local->pluck('slug')->unique()->all())->get()
            ->keyBy(fn (OffsiteBackup $row): string => $row->site_slug.'/'.$row->backup_id);

        return $local->groupBy('slug')->flatMap(function (Collection $backups, string $slug) use ($rows, $config): Collection {
            $candidates = $backups->filter(function (LocalBackup $backup) use ($rows): bool {
                $status = $rows->get($backup->slug.'/'.$backup->id)?->status;

                return $status === null || in_array($status, [OffsiteStatus::Pending, OffsiteStatus::Failed], true);
            });

            $dates = $rows->filter(fn (OffsiteBackup $row): bool => $row->site_slug === $slug && $row->status === OffsiteStatus::Uploaded)
                ->mapWithKeys(fn (OffsiteBackup $row): array => [$row->backup_id => $row->backup_created_at ?? $row->created_at])
                ->all();

            foreach ($candidates as $backup) {
                $dates[$backup->id] = $backup->createdAt;
            }

            $keep = array_flip($config->retention->keep($dates, now()));

            return $candidates->filter(fn (LocalBackup $backup): bool => isset($keep[$backup->id]));
        })->values();
    }

    /**
     * @return string|null de foutmelding, of null als het gelukt is
     */
    private function upload(LocalBackup $backup, S3Storage $storage, OffsiteConfig $config): ?string
    {
        $row = OffsiteBackup::query()->updateOrCreate(['site_slug' => $backup->slug, 'backup_id' => $backup->id], [
            'site_name' => $backup->siteName,
            'kind' => $backup->kind ?: null,
            'backup_created_at' => $backup->createdAt,
            'object_key' => $config->objectKey($backup->slug, $backup->id),
            'status' => OffsiteStatus::Uploading,
            'error' => null,
        ]);

        $temporary = $this->scanner->workDirectory().'/'.Str::uuid().'.tar.enc';

        try {
            $size = $this->archive->pack($backup->path, $temporary, (string) $config->passphrase);

            $storage->put($row->object_key, $temporary, [
                'format' => 'bddk'.BackupCipher::VERSION,
                'key-id' => (string) $config->keyId,
                'site' => $backup->siteName,
                'backup-id' => $backup->id,
                'kind' => $backup->kind,
                'created' => $backup->createdAt->toIso8601String(),
            ]);

            if (($storage->head($row->object_key)['size'] ?? null) !== $size) {
                throw new OffsiteException(__('De upload kwam niet compleet aan.'));
            }

            $row->update([
                'status' => OffsiteStatus::Uploaded,
                'size' => $size,
                'key_id' => $config->keyId,
                'uploaded_at' => now(),
            ]);

            return null;
        } catch (Throwable $exception) {
            if (! $exception instanceof OffsiteException) {
                report($exception);
            }

            $message = Str::limit($exception->getMessage(), 500);
            $row->update(['status' => OffsiteStatus::Failed, 'error' => $message]);

            return $message;
        } finally {
            @unlink($temporary);
        }
    }

    private function applyRetention(S3Storage $storage, OffsiteConfig $config): int
    {
        $pruned = 0;

        $rows = OffsiteBackup::query()->inCloud()->orderBy('site_slug')->orderBy('id')->get()->groupBy('site_slug');

        foreach ($rows as $siteRows) {
            $byId = $siteRows->keyBy('backup_id');
            $dates = $byId->map(fn (OffsiteBackup $row): CarbonImmutable => CarbonImmutable::instance($row->backup_created_at ?? $row->created_at))->all();

            foreach ($config->retention->prune($dates, now()) as $id) {
                $row = $byId->get($id);

                try {
                    $storage->delete($row->object_key);
                    $row->update(['status' => OffsiteStatus::Deleted, 'error' => null]);
                    $pruned++;
                } catch (OffsiteException $exception) {
                    // blijft staan; de volgende run probeert het opnieuw
                    $row->update(['error' => $exception->getMessage()]);
                }
            }
        }

        return $pruned;
    }

    /**
     * Alleen wat aantoonbaar in de cloud staat, en nooit de nieuwste lokale back-up van een site.
     *
     * @param  Collection<int, LocalBackup>  $local
     */
    private function cleanUpLocal(Collection $local, S3Storage $storage, OffsiteConfig $config): int
    {
        $cutoff = now()->subDays($config->cleanupLocalDays);
        $rows = OffsiteBackup::query()->inCloud()->get()->keyBy(fn (OffsiteBackup $row): string => $row->site_slug.'/'.$row->backup_id);
        $cleaned = 0;

        foreach ($local->groupBy('slug') as $backups) {
            foreach ($backups->sortByDesc(fn (LocalBackup $backup): int => $backup->createdAt->getTimestamp())->skip(1) as $backup) {
                $row = $rows->get($backup->slug.'/'.$backup->id);

                if ($row === null || $backup->createdAt->greaterThanOrEqualTo($cutoff)) {
                    continue;
                }

                try {
                    $remote = $storage->head($row->object_key);
                } catch (OffsiteException) {
                    continue;
                }

                if ($remote === null || $remote['size'] !== $row->size) {
                    continue;
                }

                File::deleteDirectory($backup->path);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Eén melding per reeks mislukte runs; een geslaagde run zet dat weer terug.
     */
    private function finish(OffsiteConfig $config, int $failed, ?string $error): void
    {
        $state = ['last_sync_at' => now()->toIso8601String()];

        if ($failed === 0) {
            $state['failure_notified'] = false;
        } elseif (! $config->failureNotified) {
            $this->notifier->offsiteBackupFailed($failed, (string) $error);
            $state['failure_notified'] = true;
        }

        $this->settings->updateOffsite($state);
    }

    private function createdAt(string $id, ?string $created, mixed $fallback): CarbonImmutable
    {
        if (is_string($created) && $created !== '') {
            return rescue(fn (): CarbonImmutable => CarbonImmutable::parse($created), fn () => $this->createdAt($id, null, $fallback), report: false);
        }

        if (preg_match('/^\d{8}-\d{6}/', $id) === 1) {
            return CarbonImmutable::createFromFormat('Ymd-His', substr($id, 0, 15)) ?: CarbonImmutable::now();
        }

        return $fallback !== null ? CarbonImmutable::instance($fallback) : CarbonImmutable::now();
    }
}
