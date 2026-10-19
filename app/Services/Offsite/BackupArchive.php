<?php

namespace App\Services\Offsite;

use App\Exceptions\OffsiteException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * Eén back-up-map wordt één versleuteld tar-bestand, en weer terug. Alles als stream:
 * tar schrijft direct in de versleuteling, er komt nooit een onversleutelde kopie op schijf.
 */
final readonly class BackupArchive
{
    public function __construct(private BackupCipher $cipher) {}

    /**
     * @return int grootte van het versleutelde bestand in bytes
     */
    public function pack(string $directory, string $target, #[SensitiveParameter] string $passphrase): int
    {
        $errors = $target.'.log';
        $process = proc_open(
            ['tar', '-C', dirname($directory), '-cf', '-', '--', basename($directory)],
            [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('tar kon niet starten.');
        }

        $output = fopen($target, 'wb');

        try {
            if ($output === false) {
                throw new RuntimeException("Kan {$target} niet schrijven.");
            }

            chmod($target, 0600);
            $this->cipher->encrypt($pipes[1], $output, $passphrase);
        } finally {
            is_resource($output) && fclose($output);
            fclose($pipes[1]);
            $exitCode = proc_close($process);
        }

        $message = trim((string) @file_get_contents($errors));
        @unlink($errors);

        if ($exitCode !== 0) {
            @unlink($target);

            throw new OffsiteException(__('Inpakken van de back-up mislukt: :error', ['error' => Str::limit($message ?: 'tar '.$exitCode, 200)]));
        }

        clearstatcache(true, $target);

        return (int) filesize($target);
    }

    /**
     * Pakt eerst uit in een werkmap en verplaatst pas als alles klopt: nooit een halve back-up tussen de echte.
     */
    public function unpack(string $encryptedFile, string $destination, string $backupId, #[SensitiveParameter] string $passphrase, string $workDirectory): void
    {
        $target = $destination.'/'.$backupId;

        if (file_exists($target)) {
            throw new OffsiteException(__('Back-up :id staat al op deze laptop.', ['id' => $backupId]));
        }

        $temporary = $workDirectory.'/restore-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($temporary, 0700);
        $errors = $temporary.'.log';

        try {
            $input = fopen($encryptedFile, 'rb');

            if ($input === false) {
                throw new RuntimeException("Kan {$encryptedFile} niet lezen.");
            }

            // GNU tar weigert zelf al absolute paden en ../, en eigenaren van een andere laptop horen er niet bij
            $process = proc_open(
                ['tar', '-C', $temporary, '--no-same-owner', '-xf', '-'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errors, 'w']],
                $pipes,
            );

            if (! is_resource($process)) {
                throw new RuntimeException('tar kon niet starten.');
            }

            try {
                $this->cipher->decrypt($input, $pipes[0], $passphrase);
            } finally {
                fclose($input);
                fclose($pipes[0]);
                $exitCode = proc_close($process);
            }

            if ($exitCode !== 0) {
                throw new OffsiteException(__('Uitpakken van de back-up mislukt: :error', ['error' => Str::limit(trim((string) @file_get_contents($errors)) ?: 'tar '.$exitCode, 200)]));
            }

            if (array_values(array_diff((array) scandir($temporary), ['.', '..'])) !== [$backupId] || ! is_dir($temporary.'/'.$backupId)) {
                throw new OffsiteException(__('De back-up bevat niet de verwachte map.'), OffsiteException::DAMAGED);
            }

            File::ensureDirectoryExists($destination, 0700);
            @chmod($destination, 0700);

            if (! rename($temporary.'/'.$backupId, $target)) {
                throw new RuntimeException("Kan de back-up niet naar {$target} verplaatsen.");
            }
        } finally {
            File::deleteDirectory($temporary);
            @unlink($errors);
        }
    }
}
