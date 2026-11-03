<?php

namespace App\Enums;

enum QuoteManagement: string
{
    case None = 'none';
    case Basic = 'basic';
    case Plus = 'plus';

    public function label(): string
    {
        return match ($this) {
            self::None => __('Geen beheer'),
            self::Basic => 'Beheer Basis',
            self::Plus => 'Beheer Plus',
        };
    }

    /**
     * Maandprijs in eurocenten, exclusief btw; null zonder beheer.
     */
    public function monthlyPriceCents(): ?int
    {
        return match ($this) {
            self::None => null,
            default => (int) config('quote.management.'.$this->value.'.price'),
        };
    }
}
