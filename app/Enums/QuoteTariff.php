<?php

namespace App\Enums;

enum QuoteTariff: string
{
    case Standard = 'standard';
    case Friend = 'friend';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standaardtarief'),
            self::Friend => __('Vriendenprijs'),
        };
    }

    /**
     * Uurtarief in eurocenten, exclusief btw.
     */
    public function hourlyRateCents(): int
    {
        return (int) config('quote.rates.'.$this->value);
    }
}
