<?php

namespace App\Services\Offsite;

use App\Exceptions\OffsiteException;
use App\Models\OffsiteBackup;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Haalt een back-up uit de cloud terug naar de back-up-map, waarna het gewone terugzetten werkt.
 */
final readonly class OffsiteRestorer
{
    public function __construct(
        private AppSettings $settings,
        private LocalBackupScanner $scanner,
        private S3StorageFactory $storageFactory,
        private BackupArchive $archive,
    ) {}

    public static function stateKey(int $offsiteBackupId): string
    {
        return 'offsite-restore:'.$offsiteBackupId;
    }

    /**
     * @return array{state: string, error: ?string}|null
     */
    public static function state(int $offsiteBackupId): ?array
    {
        $state = Cache::get(self::stateKey($offsiteBackupId));

        return is_array($state) ? $state : null;
    }

    public static function markQueued(int $offsiteBackupId): void
    {
        Cache::put(self::stateKey($offsiteBackupId), ['state' => 'running', 'error' => null], now()->addHours(6));
    }

    public function restore(OffsiteBackup $backup): void
    {
        $config = $this->settings->offsite();

        if (! $config->isComplete()) {
            throw new OffsiteException(__('Stel eerst de cloudopslag in bij Instellingen.'));
        }

        if (! $backup->isEncryptedWith($config->keyId)) {
            throw OffsiteException::wrongPassphrase();
        }

        $destination = $this->settings->backupsDirectory().'/'.$backup->site_slug;

        if (is_dir($destination.'/'.$backup->backup_id)) {
            return;
        }

        $temporary = $this->scanner->workDirectory().'/'.Str::uuid().'.tar.enc';

        try {
            $this->storageFactory->make($config)->download($backup->object_key, $temporary);
            $this->archive->unpack($temporary, $destination, $backup->backup_id, (string) $config->passphrase, $this->scanner->workDirectory());
        } finally {
            @unlink($temporary);
        }
    }
}
