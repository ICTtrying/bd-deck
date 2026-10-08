<?php

namespace App\Services;

use App\Exceptions\VaultLockedException;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;

/**
 * Geheimen worden versleuteld met een willekeurige datasleutel. Die sleutel staat alleen
 * ingepakt (met een sleutel afgeleid van het hoofdwachtwoord) in de database en ontsleuteld
 * alleen in de sessie zolang de app ontgrendeld is. Een gekopieerde database is zo waardeloos.
 */
final class Vault
{
    private const string SESSION_KEY = 'vault.key';

    private const string CIPHER = 'aes-256-gcm';

    public function __construct(private readonly Session $session) {}

    /**
     * @return array{vault_salt: string, vault_key: string}
     */
    public function create(string $password): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $dataKey = random_bytes(32);

        return [
            'vault_salt' => base64_encode($salt),
            'vault_key' => $this->wrapper($password, $salt)->encryptString(base64_encode($dataKey)),
        ];
    }

    public function initialize(User $user, string $password): void
    {
        $user->forceFill($this->create($password))->save();
        $this->unlock($user, $password);
    }

    public function unlock(User $user, string $password): void
    {
        $this->session->put(self::SESSION_KEY, $this->unwrap($user, $password));
    }

    /**
     * Alleen de datasleutel wordt opnieuw ingepakt: bestaande geheimen blijven geldig.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        $dataKey = $this->unwrap($user, $currentPassword);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);

        $user->forceFill([
            'password' => $newPassword,
            'vault_salt' => base64_encode($salt),
            'vault_key' => $this->wrapper($newPassword, $salt)->encryptString($dataKey),
        ])->save();

        $this->session->put(self::SESSION_KEY, $dataKey);
    }

    public function lock(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function isUnlocked(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter()->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->encrypter()->decryptString($ciphertext);
    }

    private function unwrap(User $user, string $password): string
    {
        if ($user->vault_salt === null || $user->vault_key === null) {
            throw new InvalidArgumentException('Deze gebruiker heeft nog geen kluis.');
        }

        try {
            return $this->wrapper($password, base64_decode($user->vault_salt))->decryptString($user->vault_key);
        } catch (DecryptException) {
            throw new InvalidArgumentException(__('Het wachtwoord past niet bij de kluis.'));
        }
    }

    private function encrypter(): Encrypter
    {
        $key = $this->session->get(self::SESSION_KEY);

        if (! is_string($key)) {
            throw new VaultLockedException;
        }

        return new Encrypter(base64_decode($key), self::CIPHER);
    }

    private function wrapper(string $password, string $salt): Encrypter
    {
        $key = sodium_crypto_pwhash(
            32,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        return new Encrypter($key, self::CIPHER);
    }
}
