<?php

use App\Enums\QuoteManagement;
use App\Enums\QuotePlatform;
use App\Enums\QuoteProjectType;
use App\Enums\QuoteTariff;
use App\Services\Quote\Quote;
use App\Services\Quote\QuoteCalculator;
use App\Services\Quote\QuoteInput;
use App\Support\Money;

function quote(QuoteInput $input): Quote
{
    return app(QuoteCalculator::class)->calculate($input);
}

it('kiest bij een website het kleinste pakket dat bij de pagina\'s past', function (int $pages, int $standard, int $friend): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, pages: $pages));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe($standard)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe($friend);
})->with([
    'start' => [3, 44900, 31500],
    'groei' => [5, 74900, 52500],
    'pro' => [8, 109500, 77000],
]);

it('rekent extra pagina\'s boven het grootste pakket en waarschuwt voor een aparte offerte', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, pages: 12));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe(109500 + 2 * 7500)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(77000 + 2 * 5250)
        ->and($quote->notes[0])->toContain('aparte offerte');
});

it('houdt een handmatig gekozen pakket aan en rekent alleen wat boven het pakket uitkomt', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, package: 'groei', pages: 8));

    expect($quote->lines)->toHaveCount(2)
        ->and($quote->lines[0]->label)->toBe('WordPress-website Groei')
        ->and($quote->lines[1]->label)->toBe('Extra pagina (2×)')
        ->and($quote->totalCents(QuoteTariff::Standard))->toBe(74900 + 15000);
});

it('valt terug op automatisch bij een onbekend pakket', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, package: 'bestaat-niet', pages: 2));

    expect($quote->lines[0]->label)->toBe('WordPress-website Start');
});

it('telt meerwerk op tegen de prijzen van de site', function (): void {
    $quote = quote(new QuoteInput(
        QuoteProjectType::Website,
        extraForms: 1,
        extraRounds: 2,
        contentPages: 3,
        replaceLogo: true,
        explanationHalfHours: 2,
    ));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe(44900 + 5000 + 10000 + 7500 + 2500 + 5000)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(31500 + 3500 + 7000 + 5250 + 1750 + 3500);
});

it('negeert negatieve invoer', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, pages: -4, extraForms: -1, extraRounds: -2));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe(44900);
});

it('houdt een kleine webshop op Start', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Webshop, products: 30));

    expect($quote->lines[0]->label)->toBe('Webshop Start (WooCommerce)')
        ->and($quote->totalCents(QuoteTariff::Standard))->toBe(99500)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(70000);
});

it('schuift een webshop door naar Groei en noemt de reden', function (string $field, mixed $value): void {
    $quote = quote(new QuoteInput(...['type' => QuoteProjectType::Webshop, 'products' => 10, $field => $value]));

    expect($quote->lines[0]->label)->toStartWith('Webshop Groei')
        ->and($quote->totalCents(QuoteTariff::Standard))->toBe(149500)
        ->and(implode(' ', $quote->notes))->toContain('Webshop Groei vanwege');
})->with([
    'varianten' => ['hasVariants', true],
    'verzendzones' => ['multipleShippingZones', true],
    'kortingscodes' => ['discountCodes', true],
    'betaalmethode' => ['extraPaymentMethod', true],
    'pagina\'s' => ['pages', 3],
    'producten' => ['products', 51],
]);

it('rekent de invoer van producten boven het pakket als schatting in uren', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Webshop, products: 120));
    $variants = quote(new QuoteInput(QuoteProjectType::Webshop, products: 120, hasVariants: true));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe(149500 + 10000)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(105000 + 7000)
        ->and($variants->totalCents(QuoteTariff::Standard))->toBe(149500 + 20000);
});

it('geeft een Shopify-webshop geen Enfold-licentie maar wel een abonnement als niet inbegrepen', function (): void {
    $shopify = quote(new QuoteInput(QuoteProjectType::Webshop, platform: QuotePlatform::Shopify));
    $woo = quote(new QuoteInput(QuoteProjectType::Webshop));

    expect(implode('|', $shopify->excluded))->toContain('Shopify-abonnement')->not->toContain('Enfold')
        ->and(implode('|', $woo->excluded))->toContain('Enfold-licentie')->not->toContain('Shopify');
});

it('rekent een webapp met verrekende intake en een minimum voor de bouw', function (): void {
    $full = quote(new QuoteInput(QuoteProjectType::WebApp, buildHours: 60));
    $small = quote(new QuoteInput(QuoteProjectType::WebApp, buildHours: 40));

    expect($full->totalCents(QuoteTariff::Standard))->toBe(300000)
        ->and($full->totalCents(QuoteTariff::Friend))->toBe(210000)
        ->and($small->totalCents(QuoteTariff::Standard))->toBe(250000)
        ->and($small->totalCents(QuoteTariff::Friend))->toBe(140000)
        ->and($small->notes[0])->toContain('minimum');
});

it('rekent alleen de intake als de bouw nog niet vaststaat', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::WebApp, includePrototype: true));

    expect($quote->totalCents(QuoteTariff::Standard))->toBe(19500 + 69500)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(14000 + 49000)
        ->and($quote->notes[0])->toContain('vaste prijs voor de bouw');
});

it('geeft beheer geen vriendenkorting en rekent jaarbetaling als 11 maanden', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website, management: QuoteManagement::Plus, yearlyBilling: true));

    expect($quote->monthlyCents())->toBe(4900)
        ->and($quote->yearlyCents())->toBe(53900)
        ->and(quote(new QuoteInput(QuoteProjectType::Website, management: QuoteManagement::Basic))->yearlyCents())->toBe(2900 * 12);
});

it('raadt bij een webshop zonder Beheer Plus dat pakket aan', function (): void {
    expect(quote(new QuoteInput(QuoteProjectType::Webshop, management: QuoteManagement::Basic))->notes)->toContain('Voor een webshop adviseer je op je site Beheer Plus (€ 49 per maand).')
        ->and(quote(new QuoteInput(QuoteProjectType::Webshop, management: QuoteManagement::Plus))->notes)->toBe(['Prijs is "vanaf": Webshop Start op de site.']);
});

it('berekent btw en het verschil tussen de tarieven', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Website));

    expect($quote->vatCents(QuoteTariff::Standard))->toBe(9429)
        ->and($quote->totalInclVatCents(QuoteTariff::Standard))->toBe(54329)
        ->and($quote->friendDiscountCents())->toBe(44900 - 31500)
        ->and($quote->totalHours())->toBe(9.0);
});

it('maakt een offertetekst met totalen, beheer en wat niet inbegrepen is', function (): void {
    $text = quote(new QuoteInput(QuoteProjectType::Website, management: QuoteManagement::Basic))->toText(QuoteTariff::Friend);

    expect($text)->toContain('Offerte: WordPress-website')
        ->toContain('- WordPress-website Start: € 315')
        ->toContain('Totaal excl. btw: € 315')
        ->toContain('Btw 21%: € 66,15')
        ->toContain('Totaal incl. btw: € 381,15')
        ->toContain('- Beheer Basis: € 29')
        ->toContain('Enfold-licentie')
        ->not->toContain('Hosting en domeinnaam');
});

it('rekent overig werk als losse uren op het gekozen tarief', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Other, otherHours: 3, otherDescription: '  Site verhuizen  '));

    expect($quote->lines)->toHaveCount(1)
        ->and($quote->lines[0]->label)->toBe('Site verhuizen')
        ->and($quote->totalCents(QuoteTariff::Standard))->toBe(15000)
        ->and($quote->totalCents(QuoteTariff::Friend))->toBe(10500)
        ->and($quote->recurring)->toBe([])
        ->and($quote->excluded)->toBe([])
        ->and($quote->toText(QuoteTariff::Friend))->toContain('Offerte: Overig werk')->toContain('- Site verhuizen: € 105');
});

it('noemt overig werk zo zonder omschrijving en laat het bij 0 uur of negatieve uren weg', function (): void {
    expect(quote(new QuoteInput(QuoteProjectType::Other, otherHours: 1.5))->lines[0]->label)->toBe('Overig werk')
        ->and(quote(new QuoteInput(QuoteProjectType::Other, otherHours: 1.5))->totalCents(QuoteTariff::Standard))->toBe(7500)
        ->and(quote(new QuoteInput(QuoteProjectType::Other))->isEmpty())->toBeTrue()
        ->and(quote(new QuoteInput(QuoteProjectType::Other, otherHours: -2))->isEmpty())->toBeTrue();
});

it('kapt een te lange omschrijving van overig werk af', function (): void {
    $quote = quote(new QuoteInput(QuoteProjectType::Other, otherHours: 1, otherDescription: str_repeat('a', 300)));

    expect(mb_strlen($quote->lines[0]->label))->toBe(120);
});

it('telt uren voor overig werk niet mee bij een website, webshop of webapplicatie', function (): void {
    expect(quote(new QuoteInput(QuoteProjectType::Website, otherHours: 5))->totalCents(QuoteTariff::Standard))->toBe(44900);
});

it('formatteert eurocenten', function (): void {
    expect(Money::format(109500))->toBe('€ 1.095')
        ->and(Money::format(1750))->toBe('€ 17,50')
        ->and(Money::format(-19500))->toBe('-€ 195')
        ->and(Money::format(0))->toBe('€ 0');
});
