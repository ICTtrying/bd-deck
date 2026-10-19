<?php

use App\Exceptions\OffsiteException;
use App\Services\Offsite\BackupArchive;
use App\Services\Offsite\BackupCipher;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->cipher = new BackupCipher;
});

it('versleutelt en ontsleutelt, ook precies op en rond de blokgrens', function (int $size): void {
    $plain = $size > 0 ? random_bytes($size) : '';
    $encrypted = $this->cipher->encryptString($plain, 'een goed wachtwoord');

    expect($encrypted)->toStartWith(BackupCipher::MAGIC)
        // korte data komt bij toeval ook in willekeurige bytes voor; pas vanaf 16 bytes zegt dit iets
        ->and($size < 16 || ! str_contains($encrypted, substr($plain, 0, 64)))->toBeTrue()
        ->and($this->cipher->decryptString($encrypted, 'een goed wachtwoord'))->toBe($plain);
})->with([0, 1, 1024 * 1024 - 1, 1024 * 1024, 1024 * 1024 + 1, 2 * 1024 * 1024 + 5]);

it('geeft elke keer een andere uitkomst door een nieuwe salt', function (): void {
    expect($this->cipher->encryptString('zelfde', 'wachtwoord-123'))->not->toBe($this->cipher->encryptString('zelfde', 'wachtwoord-123'));
});

it('herkent een ander wachtwoord', function (): void {
    $encrypted = $this->cipher->encryptString('klantdata', 'het juiste wachtwoord');

    expect(fn () => $this->cipher->decryptString($encrypted, 'een fout wachtwoord'))
        ->toThrow(fn (OffsiteException $exception) => expect($exception->reason)->toBe(OffsiteException::WRONG_PASSPHRASE));
});

it('weigert een gewijzigd, afgekapt of verlengd bestand', function (Closure $tamper): void {
    $encrypted = $this->cipher->encryptString(random_bytes(1024 * 1024 + 100), 'het juiste wachtwoord');

    expect(fn () => $this->cipher->decryptString($tamper($encrypted), 'het juiste wachtwoord'))
        ->toThrow(fn (OffsiteException $exception) => expect($exception->reason)->toBe(OffsiteException::DAMAGED));
})->with([
    'bit omgedraaid in de data' => [fn (string $data): string => substr_replace($data, chr(ord($data[500]) ^ 1), 500, 1)],
    'salt gewijzigd' => [fn (string $data): string => substr_replace($data, chr(ord($data[20]) ^ 1), 20, 1)],
    'laatste blok weg' => [fn (string $data): string => substr($data, 0, 53 + 1024 * 1024 + 17)],
    'afgekapt midden in een blok' => [fn (string $data): string => substr($data, 0, -10)],
    'extra data erachter' => [fn (string $data): string => $data.'x'],
    'geen BD Deck-bestand' => [fn (string $data): string => 'PK'.substr($data, 2)],
]);

it('weigert een onbekende versie', function (): void {
    $encrypted = $this->cipher->encryptString('x', 'het juiste wachtwoord');

    expect(fn () => $this->cipher->decryptString(substr_replace($encrypted, chr(2), 4, 1), 'het juiste wachtwoord'))
        ->toThrow(OffsiteException::class, 'nieuwere versie');
});

it('geeft per wachtwoord een vast sleutel-ID', function (): void {
    expect($this->cipher->keyId('wachtwoord-een'))->toBe((new BackupCipher)->keyId('wachtwoord-een'))
        ->and($this->cipher->keyId('wachtwoord-een'))->not->toBe($this->cipher->keyId('wachtwoord-twee'))
        ->and($this->cipher->keyId('wachtwoord-een'))->toMatch('/^[0-9a-f]{16}$/');
});

it('pakt een back-up-map in en weer uit zonder onversleutelde kopie', function (): void {
    $root = sys_get_temp_dir().'/bd-deck-archive-'.uniqid();
    File::ensureDirectoryExists($root.'/src/20261014-100000-push/live-files/wp-content');
    file_put_contents($root.'/src/20261014-100000-push/meta', "kind=push\n");
    file_put_contents($root.'/src/20261014-100000-push/live-files/wp-content/style.css', 'body{}');
    File::ensureDirectoryExists($root.'/work');

    $archive = new BackupArchive($this->cipher);
    $size = $archive->pack($root.'/src/20261014-100000-push', $root.'/backup.tar.enc', 'het juiste wachtwoord');

    expect($size)->toBe(filesize($root.'/backup.tar.enc'))
        ->and(str_contains((string) file_get_contents($root.'/backup.tar.enc'), 'kind=push'))->toBeFalse();

    $archive->unpack($root.'/backup.tar.enc', $root.'/dest', '20261014-100000-push', 'het juiste wachtwoord', $root.'/work');

    expect(file_get_contents($root.'/dest/20261014-100000-push/live-files/wp-content/style.css'))->toBe('body{}')
        ->and(File::directories($root.'/work'))->toBe([]);

    expect(fn () => $archive->unpack($root.'/backup.tar.enc', $root.'/other', '20261014-100000-push', 'fout wachtwoord!', $root.'/work'))
        ->toThrow(OffsiteException::class)
        ->and($root.'/other/20261014-100000-push')->not->toBeDirectory()
        ->and(File::directories($root.'/work'))->toBe([]);

    expect(fn () => $archive->unpack($root.'/backup.tar.enc', $root.'/other', 'een-andere-id', 'het juiste wachtwoord', $root.'/work'))
        ->toThrow(OffsiteException::class, 'verwachte map');

    File::deleteDirectory($root);
});
