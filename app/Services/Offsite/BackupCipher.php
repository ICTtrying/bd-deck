<?php

namespace App\Services\Offsite;

use App\Exceptions\OffsiteException;
use RuntimeException;
use SensitiveParameter;

/**
 * Versleutelt back-ups op de laptop voordat ze vertrekken: de opslagdienst ziet nooit klantdata.
 *
 * Formaat (versie 1): "BDDK" | versie (1 byte) | sleutel-ID (8 bytes) | salt (16 bytes) | secretstream-header (24 bytes),
 * daarna blokken van 1 MiB met XChaCha20-Poly1305 (libsodium secretstream). Het laatste blok draagt TAG_FINAL,
 * zodat een afgekapt bestand opvalt. De sleutel komt uit argon2id over het wachtwoord met de salt.
 */
final class BackupCipher
{
    public const string MAGIC = 'BDDK';

    public const int VERSION = 1;

    private const int CHUNK_SIZE = 1024 * 1024;

    /**
     * Vaste salt voor de vingerafdruk: hetzelfde wachtwoord moet op elke laptop hetzelfde sleutel-ID geven.
     */
    private const string KEY_ID_SALT = 'bd-deck/key-id/1';

    private const int KEY_ID_BYTES = 8;

    /**
     * Kleinste uitvoer die crypto_pwhash toestaat; het ID gebruikt daar de eerste 8 bytes van.
     */
    private const int KEY_ID_HASH_BYTES = 16;

    /**
     * @var array<string, string>
     */
    private array $keyIds = [];

    /**
     * Korte vingerafdruk van het wachtwoord, om te zien met welk wachtwoord een back-up versleuteld is.
     */
    public function keyId(#[SensitiveParameter] string $passphrase): string
    {
        $this->ensureSodium();

        return $this->keyIds[hash('sha256', $passphrase)] ??= bin2hex(substr(
            sodium_crypto_pwhash(self::KEY_ID_HASH_BYTES, $passphrase, self::KEY_ID_SALT, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13),
            0,
            self::KEY_ID_BYTES,
        ));
    }

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function encrypt($input, $output, #[SensitiveParameter] string $passphrase): void
    {
        $this->ensureSodium();

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = $this->deriveKey($passphrase, $salt);
        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);

        // de vaste header gaat als extra data mee in elk blok, zodat ook daar niet mee geknoeid kan worden
        $prefix = self::MAGIC.chr(self::VERSION).hex2bin($this->keyId($passphrase)).$salt;
        $this->write($output, $prefix.$streamHeader);

        $chunk = $this->read($input, self::CHUNK_SIZE);

        do {
            $next = $this->read($input, self::CHUNK_SIZE);
            $final = $next === '';
            $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $this->write($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $prefix, $tag));
            $chunk = $next;
        } while (! $final);
    }

    /**
     * @param  resource  $input
     * @param  resource  $output
     *
     * @throws OffsiteException bij een ander wachtwoord of een beschadigd bestand
     */
    public function decrypt($input, $output, #[SensitiveParameter] string $passphrase): void
    {
        $this->ensureSodium();

        $prefix = $this->read($input, strlen(self::MAGIC) + 1 + self::KEY_ID_BYTES + SODIUM_CRYPTO_PWHASH_SALTBYTES);

        if (strlen($prefix) !== strlen(self::MAGIC) + 1 + self::KEY_ID_BYTES + SODIUM_CRYPTO_PWHASH_SALTBYTES || ! str_starts_with($prefix, self::MAGIC)) {
            throw new OffsiteException(__('Dit is geen back-up van BD Deck.'), OffsiteException::DAMAGED);
        }

        if (ord($prefix[4]) !== self::VERSION) {
            throw new OffsiteException(__('Deze back-up is gemaakt met een nieuwere versie van BD Deck. Werk de app eerst bij.'), OffsiteException::DAMAGED);
        }

        if (bin2hex(substr($prefix, 5, self::KEY_ID_BYTES)) !== $this->keyId($passphrase)) {
            throw OffsiteException::wrongPassphrase();
        }

        $streamHeader = $this->read($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw OffsiteException::damaged();
        }

        $key = $this->deriveKey($passphrase, substr($prefix, 5 + self::KEY_ID_BYTES));
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
        sodium_memzero($key);

        while (true) {
            $chunk = $this->read($input, self::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            // een einde zonder TAG_FINAL betekent een afgekapt bestand
            $result = $chunk === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk, $prefix);

            if ($result === false) {
                throw OffsiteException::damaged();
            }

            [$plain, $tag] = $result;
            $this->write($output, $plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if ($this->read($input, 1) !== '') {
                    throw OffsiteException::damaged();
                }

                return;
            }
        }
    }

    public function encryptString(string $plain, #[SensitiveParameter] string $passphrase): string
    {
        $input = $this->memoryStream($plain);
        $output = $this->memoryStream();
        $this->encrypt($input, $output, $passphrase);
        rewind($output);

        return (string) stream_get_contents($output);
    }

    public function decryptString(string $encrypted, #[SensitiveParameter] string $passphrase): string
    {
        $input = $this->memoryStream($encrypted);
        $output = $this->memoryStream();
        $this->decrypt($input, $output, $passphrase);
        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function deriveKey(#[SensitiveParameter] string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * Pipes (tar) geven kleine stukjes terug; blijft lezen tot het blok vol is of de stream op.
     *
     * @param  resource  $stream
     */
    private function read($stream, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($stream)) {
            $data = fread($stream, $length - strlen($buffer));

            if ($data === false) {
                throw new RuntimeException('Lezen van de back-up mislukt.');
            }

            $buffer .= $data;
        }

        return $buffer;
    }

    /**
     * @param  resource  $stream
     */
    private function write($stream, string $data): void
    {
        for ($written = 0; $written < strlen($data); $written += $result) {
            $result = fwrite($stream, substr($data, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException('Schrijven van de back-up mislukt.');
            }
        }
    }

    /**
     * @return resource
     */
    private function memoryStream(string $contents = '')
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Kan geen tijdelijke stream openen.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function ensureSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new OffsiteException(__('Deze PHP-versie kan niet versleutelen (sodium ontbreekt).'));
        }
    }
}
