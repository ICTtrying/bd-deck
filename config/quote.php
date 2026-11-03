<?php

/*
|--------------------------------------------------------------------------
| Prijslijst voor de calculator
|--------------------------------------------------------------------------
|
| Alle bedragen in eurocenten, exclusief btw. De pakketprijzen en meerwerktarieven komen van
| borgmandigital.nl (gelezen op 2026-11-03). Bij elk onderdeel staat ook het aantal uren
| waarop de prijs gebaseerd is: de vriendenprijs rekent uren x vriendentarief. Uren die niet
| op de site staan zijn een schatting en staan hieronder gemarkeerd met "schatting".
|
*/

return [
    'vat_percentage' => 21,

    'rates' => [
        'standard' => 5000,
        'friend' => 3500,
    ],

    // Pakketprijs = uren x €50 min €1, daarom de uren hieronder.
    'website_packages' => [
        'start' => ['label' => 'Start', 'price' => 44900, 'hours' => 9, 'pages' => 3, 'forms' => 1, 'rounds' => 2, 'aftercare_days' => 14],
        'groei' => ['label' => 'Groei', 'price' => 74900, 'hours' => 15, 'pages' => 6, 'forms' => 2, 'rounds' => 2, 'aftercare_days' => 30],
        'pro' => ['label' => 'Pro', 'price' => 109500, 'hours' => 22, 'pages' => 10, 'forms' => 3, 'rounds' => 3, 'aftercare_days' => 30],
    ],

    // Prijzen zijn "vanaf". Op de site staat geen aantal formulieren voor webshops, dus 0 inbegrepen.
    // products voor Groei (100) is een schatting: de site zegt alleen "meer dan 50".
    'webshop_packages' => [
        'start' => ['label' => 'Webshop Start', 'price' => 99500, 'hours' => 20, 'products' => 50, 'pages' => 2, 'forms' => 0, 'rounds' => 2, 'aftercare_days' => 14],
        'groei' => ['label' => 'Webshop Groei', 'price' => 149500, 'hours' => 30, 'products' => 100, 'pages' => 4, 'forms' => 0, 'rounds' => 3, 'aftercare_days' => 30],
    ],

    // Schatting: invoertijd per product boven het inbegrepen aantal (6 min, met varianten 12 min).
    'webshop' => [
        'product_hours' => 0.1,
        'variant_product_hours' => 0.2,
        'custom_quote_products' => 500,
    ],

    'extras' => [
        'page' => ['label' => 'Extra pagina', 'hours' => 1.5, 'price' => 7500],
        'form' => ['label' => 'Extra formulier', 'hours' => 1.0, 'price' => 5000],
        'round' => ['label' => 'Extra wijzigingsronde', 'hours' => 1.0, 'price' => 5000],
        'content' => ['label' => 'Teksten en afbeeldingen plaatsen (per pagina)', 'hours' => 0.5, 'price' => 2500],
        'logo' => ['label' => 'Logo en favicon plaatsen', 'hours' => 0.5, 'price' => 2500],
        'explanation' => ['label' => 'Uitleg over zelf bijhouden (per half uur)', 'hours' => 0.5, 'price' => 2500],
    ],

    'webapp' => [
        'intake' => ['label' => 'Intakesessie', 'hours' => 4.0, 'price' => 19500],
        'prototype' => ['label' => 'Klikbaar prototype', 'hours' => 14.0, 'price' => 69500],
        'build_minimum_price' => 250000,
        'build_default_hours' => 50,
        'hosting_price' => 7500,
    ],

    // Beheer is vast geprijsd en krijgt geen vriendenkorting. Bij jaarbetaling 11 maanden.
    'management' => [
        'basic' => ['label' => 'Beheer Basis', 'price' => 2900],
        'plus' => ['label' => 'Beheer Plus', 'price' => 4900],
    ],
    'yearly_billed_months' => 11,

    'enfold_license_dollars' => 59,
];
