<?php

namespace App\Enums;

enum QuoteProjectType: string
{
    case Website = 'website';
    case Webshop = 'webshop';
    case WebApp = 'webapp';

    public function label(): string
    {
        return match ($this) {
            self::Website => __('Website'),
            self::Webshop => __('Webshop'),
            self::WebApp => __('Webapplicatie'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Website => __('WordPress met Enfold: Start, Groei of Pro.'),
            self::Webshop => __('WooCommerce of Shopify, van kleine shop tot groot assortiment.'),
            self::WebApp => __('Maatwerk in Laravel: intake, prototype en bouw.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Website => 'globe',
            self::Webshop => 'shopify',
            self::WebApp => 'code',
        };
    }

    /**
     * Beginwaarde voor "aantal pagina's": bij een webshop gaat het om pagina's naast de shop.
     */
    public function defaultPages(): int
    {
        return match ($this) {
            self::Website => 3,
            self::Webshop => 2,
            self::WebApp => 0,
        };
    }

    public function isShop(): bool
    {
        return $this === self::Webshop;
    }

    public function usesPackages(): bool
    {
        return $this !== self::WebApp;
    }
}
