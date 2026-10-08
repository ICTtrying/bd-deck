<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Setup;
use App\Models\User;
use App\Services\Vault;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    Process::fake(['*' => Process::result('[]')]);
});

it('stuurt bij de eerste start naar de welkomstpagina', function (): void {
    $this->get(route('dashboard'))->assertRedirect();
    $this->get(route('login'))->assertRedirect(route('setup'));
});

it('maakt bij het instellen een eigenaar met kluis en logt in', function (): void {
    Livewire::test(Setup::class)
        ->set('password', 'een-lang-wachtwoord')
        ->set('password_confirmation', 'een-lang-wachtwoord')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    $owner = User::owner();

    expect($owner)->not->toBeNull()
        ->and($owner->vault_key)->not->toBeNull()
        ->and(auth()->id())->toBe($owner->id)
        ->and(app(Vault::class)->isUnlocked())->toBeTrue();
});

it('eist een sterk genoeg hoofdwachtwoord', function (): void {
    Livewire::test(Setup::class)
        ->set('password', 'kort')
        ->set('password_confirmation', 'kort')
        ->call('save')
        ->assertHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('laat geen tweede eigenaar aanmaken', function (): void {
    User::factory()->owner()->create();

    Livewire::test(Setup::class)->assertRedirect(route('login'));
});

it('ontgrendelt met het juiste wachtwoord', function (): void {
    User::factory()->owner('juist-wachtwoord')->create();

    Livewire::test(Login::class)
        ->set('password', 'juist-wachtwoord')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(app(Vault::class)->isUnlocked())->toBeTrue();
});

it('weigert een verkeerd wachtwoord en remt na vijf pogingen', function (): void {
    User::factory()->owner('juist-wachtwoord')->create();

    foreach (range(1, 5) as $attempt) {
        Livewire::test(Login::class)->set('password', 'fout')->call('login')->assertHasErrors(['password']);
    }

    Livewire::test(Login::class)
        ->set('password', 'juist-wachtwoord')
        ->call('login')
        ->assertHasErrors(['password'])
        ->assertSee('Te veel pogingen');

    $this->assertGuest();
});

it('vergrendelt en sluit de kluis af', function (): void {
    $user = User::factory()->owner('juist-wachtwoord')->create();
    app(Vault::class)->unlock($user, 'juist-wachtwoord');

    $this->actingAs($user)->post(route('lock'))->assertRedirect(route('login'));

    $this->assertGuest();
});
