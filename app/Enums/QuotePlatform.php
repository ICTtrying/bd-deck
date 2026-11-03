<?php

namespace App\Enums;

enum QuotePlatform: string
{
    case WooCommerce = 'woocommerce';
    case Shopify = 'shopify';

    public function label(): string
    {
        return match ($this) {
            self::WooCommerce => 'WooCommerce',
            self::Shopify => 'Shopify',
        };
    }
}
