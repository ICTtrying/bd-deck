<?php

namespace App\Enums;

/**
 * wpopen herkent bij het toevoegen wat er op de server staat; een Shopify-project heeft geen server maar een winkel.
 */
enum SiteType: string
{
    case WordPress = 'wordpress';
    case Laravel = 'laravel';
    case Shopify = 'shopify';

    public function label(): string
    {
        return match ($this) {
            self::WordPress => 'WordPress',
            self::Laravel => 'Laravel',
            self::Shopify => 'Shopify',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WordPress => 'wordpress',
            self::Laravel => 'code',
            self::Shopify => 'shopify',
        };
    }
}
