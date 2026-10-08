<?php

use App\Exceptions\VaultLockedException;
use App\Models\Credential;
use App\Models\User;
use App\Services\Vault;

beforeEach(function (): void {
    $this->user = User::factory()->owner('juist-wachtwoord')->create();
    $this->vault = app(Vault::class);
});

it('versleutelt geheimen alleen met een ontgrendelde kluis', function (): void {
    expect(fn () => $this->vault->encrypt('x'))->toThrow(VaultLockedException::class);

    $this->vault->unlock($this->user, 'juist-wachtwoord');
    $ciphertext = $this->vault->encrypt('ssh-wachtwoord');

    expect($ciphertext)->not->toContain('ssh-wachtwoord')
        ->and($this->vault->decrypt($ciphertext))->toBe('ssh-wachtwoord');
});

it('weigert een verkeerd wachtwoord', function (): void {
    $this->vault->unlock($this->user, 'fout');
})->throws(InvalidArgumentException::class);

it('bewaart geheimen nooit leesbaar in de database', function (): void {
    $this->vault->unlock($this->user, 'juist-wachtwoord');

    $credential = Credential::factory()->create(['secret' => 'api-sleutel-123']);

    expect($credential->getRawOriginal('secret'))->not->toContain('api-sleutel-123')
        ->and($credential->fresh()->secret->reveal($this->vault))->toBe('api-sleutel-123')
        ->and($credential->fresh()->toArray())->not->toHaveKey('secret');
});

it('houdt geheimen leesbaar na een wachtwoordwijziging', function (): void {
    $this->vault->unlock($this->user, 'juist-wachtwoord');
    $credential = Credential::factory()->create(['secret' => 'blijft-geldig']);

    $this->vault->changePassword($this->user, 'juist-wachtwoord', 'nieuw-wachtwoord');
    $this->vault->lock();

    expect(fn () => $this->vault->unlock($this->user->fresh(), 'juist-wachtwoord'))->toThrow(InvalidArgumentException::class);

    $this->vault->unlock($this->user->fresh(), 'nieuw-wachtwoord');

    expect($credential->fresh()->secret->reveal($this->vault))->toBe('blijft-geldig');
});
