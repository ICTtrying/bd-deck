<?php

namespace App\Enums;

/**
 * wpopen herkent bij het toevoegen wat er op de server staat.
 */
enum SiteType: string
{
    case WordPress = 'wordpress';
    case Laravel = 'laravel';

    public function label(): string
    {
        return match ($this) {
            self::WordPress => 'WordPress',
            self::Laravel => 'Laravel',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WordPress => 'wordpress',
            self::Laravel => 'code',
        };
    }
}
