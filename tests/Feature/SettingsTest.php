<?php

use App\Livewire\Settings\Index;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\Vault;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->owner('oud-wachtwoord-123')->create();
    app(Vault::class)->unlock($this->user, 'oud-wachtwoord-123');
    $this->actingAs($this->user);
});

it('slaat instellingen op', function (): void {
    Livewire::test(Index::class)
        ->set('editor', 'cursor')
        ->set('theme', 'dark')
        ->set('autoLockMinutes', 10)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(AppSettings::class);
    expect($settings->editor())->toBe('cursor')
        ->and($settings->theme()->value)->toBe('dark')
        ->and($settings->autoLockMinutes())->toBe(10);
});

it('weigert een editorcommando met shell-tekens', function (): void {
    Livewire::test(Index::class)->set('editor', 'code; rm -rf ~')->call('save')->assertHasErrors('editor');
});

it('wijzigt het hoofdwachtwoord alleen met het juiste huidige wachtwoord', function (): void {
    Livewire::test(Index::class)
        ->set('currentPassword', 'fout')
        ->set('newPassword', 'nieuw-wachtwoord-456')
        ->set('newPassword_confirmation', 'nieuw-wachtwoord-456')
        ->call('changePassword')
        ->assertHasErrors('currentPassword');

    Livewire::test(Index::class)
        ->set('currentPassword', 'oud-wachtwoord-123')
        ->set('newPassword', 'nieuw-wachtwoord-456')
        ->set('newPassword_confirmation', 'nieuw-wachtwoord-456')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect(Hash::check('nieuw-wachtwoord-456', $this->user->fresh()->password))->toBeTrue();
});

it('toont de app in het Engels als die taal gekozen is', function (): void {
    Process::fake(['*' => Process::result('[]')]);
    app(AppSettings::class)->update(['locale' => 'en']);

    $this->get(route('dashboard'))->assertSee('Connect your first site')->assertDontSee('Koppel je eerste site');
});
