<?php

use App\Enums\QuoteManagement;
use App\Enums\QuoteProjectType;
use App\Enums\QuoteTariff;
use App\Livewire\Calculator\Index;
use App\Livewire\CommandPalette;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->owner()->create());
});

it('toont de calculator met een website op het standaardtarief', function (): void {
    $this->get(route('calculator.index'))
        ->assertOk()
        ->assertSee('Calculator')
        ->assertSee('WordPress-website Start')
        ->assertSee('€ 449')
        ->assertSee('€ 543,29');
});

it('rekent opnieuw bij een ander tarief', function (): void {
    Livewire::test(Index::class)
        ->assertSee('€ 449')
        ->set('tariff', QuoteTariff::Friend->value)
        ->assertSee('€ 315')
        ->assertSee('Scheelt € 134');
});

it('rekent mee met pagina\'s en beheer', function (): void {
    Livewire::test(Index::class)
        ->set('pages', 8)
        ->assertSee('WordPress-website Pro')
        ->set('management', QuoteManagement::Plus->value)
        ->set('yearlyBilling', true)
        ->assertSee('Beheer Plus')
        ->assertSee('Jaarlijks vooraf: € 539');
});

it('wisselt van soort opdracht en vult de bouwuren van een webapp', function (): void {
    Livewire::test(Index::class)
        ->call('selectType', 'webshop')
        ->assertSet('type', QuoteProjectType::Webshop)
        ->assertSee('Webshop Start (WooCommerce)')
        ->call('selectType', 'webapp')
        ->assertSet('type', QuoteProjectType::WebApp)
        ->assertSet('buildHours', 50.0)
        ->assertSee('Bouw eerste versie')
        ->call('selectType', 'onzin')
        ->assertSet('type', QuoteProjectType::WebApp);
});

it('rekent door als een getalveld leeg wordt gelaten', function (): void {
    Livewire::test(Index::class)
        ->set('pages', '')
        ->assertHasNoErrors()
        ->set('otherHours', '')
        ->assertHasNoErrors()
        ->assertSee('WordPress-website Start');
});

it('staat in het menu en het commandopalet', function (): void {
    $this->get(route('dashboard'))->assertSee(route('calculator.index'), false);

    Livewire::test(CommandPalette::class)
        ->set('query', 'calcu')
        ->assertSee('Calculator');
});
