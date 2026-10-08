<?php

namespace App\Support;

use RuntimeException;

/**
 * Eigen APP_KEY per installatie. De meegeleverde .env zit in elke gedeelde AppImage; met die
 * sleutel zou iedereen andermans sessie (met de ontgrendelde kluissleutel) kunnen lezen.
 */
final readonly class InstallationKey
{
    public function __construct(private string $path) {}

    public static function in(string $storagePath): self
    {
        return new self(rtrim($storagePath, '/').'/app/installation.key');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(): string
    {
        if (! is_file($this->path)) {
            $this->create();
        }

        $key = trim((string) file_get_contents($this->path));

        if (! str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
            throw new RuntimeException('De installatiesleutel in '.$this->path.' is beschadigd.');
        }

        return $key;
    }

    /**
     * Venster en queue-workers starten tegelijk: link() faalt als een ander proces al won,
     * zodat iedereen dezelfde sleutel leest en er nooit een half geschreven bestand ligt.
     */
    private function create(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(6));
        file_put_contents($temporary, 'base64:'.base64_encode(random_bytes(32)));
        chmod($temporary, 0600);

        @link($temporary, $this->path);
        unlink($temporary);
    }
}
