<?php

namespace App\Enums;

enum SiteProvider: string
{
    case Wpmudev = 'wpmudev';
    case Hostinger = 'hostinger';
    case Other = 'other';
    case Shopify = 'shopify';

    /**
     * De hostingpartijen die je bij een SSH- of SFTP-site kunt kiezen; Shopify hoort daar niet bij.
     *
     * @return list<self>
     */
    public static function hosting(): array
    {
        return [self::Wpmudev, self::Hostinger, self::Other];
    }

    public function label(): string
    {
        return match ($this) {
            self::Wpmudev => 'WPMU DEV',
            self::Hostinger => 'Hostinger',
            self::Other => __('Overig'),
            self::Shopify => 'Shopify',
        };
    }

    public function dashboardUrl(): ?string
    {
        return match ($this) {
            self::Wpmudev => 'https://wpmudev.com/hub2/',
            self::Hostinger => 'https://hpanel.hostinger.com/',
            self::Other => null,
            self::Shopify => 'https://admin.shopify.com/',
        };
    }
}
