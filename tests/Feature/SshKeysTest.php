<?php

use App\Services\SshKeys;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalHome = getenv('HOME');
    $this->home = sys_get_temp_dir().'/bd-deck-keys-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->home.'/.ssh');
    putenv('HOME='.$this->home);
});

afterEach(function (): void {
    putenv('HOME='.$this->originalHome);
    File::deleteDirectory($this->home);
});

it('maakt een ed25519-sleutel en toont alleen het publieke deel', function (): void {
    $keys = app(SshKeys::class);

    $generated = $keys->generate('werk', 'wesley@borgmandigital.nl');
    $listed = collect($keys->all())->firstWhere('name', 'werk');

    $private = (string) file_get_contents($this->home.'/.ssh/werk');

    expect($generated['public_key'])->toStartWith('ssh-ed25519 ')->toEndWith('wesley@borgmandigital.nl')
        ->and($listed)->toMatchArray(['type' => 'ed25519', 'has_private_key' => true, 'comment' => 'wesley@borgmandigital.nl'])
        ->and($listed['fingerprint'])->toStartWith('SHA256:')
        ->and(json_encode($keys->all()))->not->toContain(trim(explode("\n", $private)[1]))
        ->and(substr(sprintf('%o', fileperms($this->home.'/.ssh/werk')), -3))->toBe('600');
});

it('overschrijft nooit een bestaande sleutel', function (): void {
    $keys = app(SshKeys::class);
    $keys->generate('werk', 'a');

    $keys->generate('werk', 'b');
})->throws(InvalidArgumentException::class, 'Er bestaat al een sleutel met deze naam.');

it('weigert namen die buiten ~/.ssh wijzen', function (string $name): void {
    app(SshKeys::class)->generate($name, 'x');
})->with(['../id_ed25519', 'werk/../../x', 'met spatie'])->throws(InvalidArgumentException::class);

it('geeft alleen sleutels uit ~/.ssh terug', function (): void {
    app(SshKeys::class)->publicKey('../../etc/passwd');
})->throws(InvalidArgumentException::class);
