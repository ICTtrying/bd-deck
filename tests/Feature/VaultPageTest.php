<?php

use App\Enums\CredentialKind;
use App\Livewire\Vault\Index;
use App\Models\Credential;
use App\Models\User;
use App\Services\Vault;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->owner('juist-wachtwoord')->create();
    app(Vault::class)->unlock($user, 'juist-wachtwoord');
    $this->actingAs($user);
});

it('bewaart een geheim versleuteld en toont het alleen op verzoek', function (): void {
    $component = Livewire::test(Index::class)
        ->set('label', 'WPMU DEV API')
        ->set('kind', CredentialKind::ApiKey->value)
        ->set('secret', 'sk-geheime-sleutel')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee('sk-geheime-sleutel');

    $credential = Credential::query()->sole();
    expect($credential->getRawOriginal('secret'))->not->toContain('sk-geheime-sleutel');

    $component->call('reveal', $credential->id)
        ->assertSet('revealedSecret', 'sk-geheime-sleutel')
        ->call('hide')
        ->assertSet('revealedSecret', null);
});

it('houdt het geheim bij bewerken als het veld leeg blijft', function (): void {
    Livewire::test(Index::class)->set('label', 'X')->set('secret', 'oud')->call('save');
    $credential = Credential::query()->sole();

    Livewire::test(Index::class)->call('edit', $credential->id)->set('label', 'Nieuw')->call('save')->assertHasNoErrors();

    expect($credential->fresh()->label)->toBe('Nieuw')
        ->and($credential->fresh()->secret->reveal(app(Vault::class)))->toBe('oud');
});

it('opent het venster met de naam die de modal verwacht', function (): void {
    // een positionele parameter komt in de browser als array binnen en opent dan niets
    Livewire::test(Index::class)
        ->call('create')
        ->assertDispatched('open-modal', name: 'credential');
});
