<?php

use App\Livewire\Dashboard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    Process::fake(['*' => Process::result('[]')]);
    $this->actingAs(User::factory()->owner()->create());
});

it('toont de sites met hun brug tussen lokaal en live', function (): void {
    Site::factory()->built()->create(['name' => 'borgmandigital']);
    Site::factory()->create(['name' => 'rijschool']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('borgmandigital')
        ->assertSee('rijschool')
        ->assertSee('Draait')
        ->assertSee('Nog niet gebouwd');
});

it('zoekt op naam en filtert op favorieten', function (): void {
    Site::factory()->favorite()->create(['name' => 'klant-a']);
    Site::factory()->create(['name' => 'klant-b']);

    Livewire::test(Dashboard::class)
        ->set('search', 'klant-b')
        ->assertSee('klant-b')
        ->assertDontSee('klant-a')
        ->set('search', '')
        ->set('scope', 'favorites')
        ->assertSee('klant-a')
        ->assertDontSee('klant-b');
});

it('maakt een site favoriet', function (): void {
    $site = Site::factory()->create();

    Livewire::test(Dashboard::class)->call('toggleFavorite', $site->id);

    expect($site->fresh()->is_favorite)->toBeTrue();
});

it('toont een lege staat met de eerste stap', function (): void {
    $this->get(route('dashboard'))->assertSee('Koppel je eerste site')->assertSee('Bestaande verbindingen zoeken');
});
