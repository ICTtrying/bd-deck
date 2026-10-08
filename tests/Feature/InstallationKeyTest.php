<?php

use App\Support\InstallationKey;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->storage = sys_get_temp_dir().'/bd-deck-key-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->storage);
});

it('maakt bij de eerste start een eigen sleutel die alleen de gebruiker kan lezen', function (): void {
    $installationKey = InstallationKey::in($this->storage);

    $key = $installationKey->get();

    expect($key)->toStartWith('base64:')
        ->and(strlen(base64_decode(substr($key, 7))))->toBe(32)
        ->and($key)->not->toBe(config('app.key'))
        ->and(fileperms($installationKey->path()) & 0777)->toBe(0600);
});

it('geeft bij elke start dezelfde sleutel terug', function (): void {
    expect(InstallationKey::in($this->storage)->get())->toBe(InstallationKey::in($this->storage)->get());
});

it('geeft elke installatie een andere sleutel', function (): void {
    $other = $this->storage.'-ander';

    try {
        expect(InstallationKey::in($this->storage)->get())->not->toBe(InstallationKey::in($other)->get());
    } finally {
        File::deleteDirectory($other);
    }
});

it('weigert een beschadigde sleutel in plaats van stilletjes een nieuwe te maken', function (): void {
    File::ensureDirectoryExists($this->storage.'/app');
    file_put_contents($this->storage.'/app/installation.key', 'kapot');

    InstallationKey::in($this->storage)->get();
})->throws(RuntimeException::class, 'beschadigd');
