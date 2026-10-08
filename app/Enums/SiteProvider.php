<?php

namespace App\Enums;

enum SiteProvider: string
{
    case Wpmudev = 'wpmudev';
    case Hostinger = 'hostinger';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Wpmudev => 'WPMU DEV',
            self::Hostinger => 'Hostinger',
            self::Other => __('Overig'),
        };
    }

    public function dashboardUrl(): ?string
    {
        return match ($this) {
            self::Wpmudev => 'https://wpmudev.com/hub2/',
            self::Hostinger => 'https://hpanel.hostinger.com/',
            self::Other => null,
        };
    }
}
