<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Werkt uitsluitend met publieke sleutels; private sleutels worden nooit gelezen of getoond.
 */
final class SshKeys
{
    public function __construct(private readonly AppSettings $settings) {}

    public function directory(): string
    {
        return $this->settings->homeDirectory().'/.ssh';
    }

    /**
     * @return list<array{name: string, path: string, type: string, bits: string, fingerprint: string, comment: string, public_key: string, has_private_key: bool, is_default: bool}>
     */
    public function all(): array
    {
        $default = $this->settings->sshKeyPath().'.pub';

        return collect(glob($this->directory().'/*.pub') ?: [])
            ->sort()
            ->map(function (string $path) use ($default): array {
                $publicKey = trim((string) file_get_contents($path));
                [$type, , $comment] = array_pad(explode(' ', $publicKey, 3), 3, '');
                $fingerprint = $this->fingerprint($path);

                return [
                    'name' => basename($path, '.pub'),
                    'path' => $path,
                    'type' => Str::after($type, 'ssh-'),
                    'bits' => $fingerprint['bits'],
                    'fingerprint' => $fingerprint['hash'],
                    'comment' => $comment,
                    'public_key' => $publicKey,
                    'has_private_key' => is_file(Str::beforeLast($path, '.pub')),
                    'is_default' => $path === $default,
                ];
            })
            ->values()
            ->all();
    }

    public function publicKey(string $name): string
    {
        $key = collect($this->all())->firstWhere('name', $name);

        if ($key === null) {
            throw new InvalidArgumentException(__('Sleutel niet gevonden.'));
        }

        return $key['public_key'];
    }

    /**
     * @return array{name: string, public_key: string}
     */
    public function generate(string $name, string $comment, ?string $passphrase = null): array
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException(__('Gebruik alleen letters, cijfers, punt, - en _ in de naam.'));
        }

        $path = $this->directory().'/'.$name;

        if (file_exists($path) || file_exists($path.'.pub')) {
            throw new InvalidArgumentException(__('Er bestaat al een sleutel met deze naam.'));
        }

        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0700, true);
        }

        Process::timeout(30)->run([
            'ssh-keygen', '-q', '-t', 'ed25519', '-a', '64', '-f', $path, '-N', (string) $passphrase, '-C', $comment,
        ])->throw();

        return ['name' => $name, 'public_key' => trim((string) file_get_contents($path.'.pub'))];
    }

    /**
     * @return array{bits: string, hash: string}
     */
    private function fingerprint(string $path): array
    {
        $result = Process::timeout(5)->run(['ssh-keygen', '-l', '-f', $path]);
        [$bits, $hash] = array_pad(explode(' ', trim($result->output())), 2, '');

        return ['bits' => $bits, 'hash' => $hash];
    }
}
