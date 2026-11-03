<?php

namespace App\Services\Quote;

use App\Enums\QuoteTariff;

/**
 * Eén eenmalige regel in de offerte. Bedragen in eurocenten, exclusief btw; negatief voor een verrekening.
 */
final readonly class QuoteLine
{
    public function __construct(
        public string $label,
        public ?string $detail,
        public float $hours,
        public int $standardCents,
        public int $friendCents,
    ) {}

    /**
     * Regel met een vaste prijs op het standaardtarief; de vriendenprijs rekent uren x vriendentarief.
     */
    public static function fixed(string $label, ?string $detail, float $hours, int $standardCents): self
    {
        return new self($label, $detail, $hours, $standardCents, self::hoursCents($hours, QuoteTariff::Friend));
    }

    /**
     * Regel die op beide tarieven gewoon uren x uurtarief kost.
     */
    public static function hourly(string $label, ?string $detail, float $hours): self
    {
        return new self(
            $label,
            $detail,
            $hours,
            self::hoursCents($hours, QuoteTariff::Standard),
            self::hoursCents($hours, QuoteTariff::Friend),
        );
    }

    public function cents(QuoteTariff $tariff): int
    {
        return match ($tariff) {
            QuoteTariff::Standard => $this->standardCents,
            QuoteTariff::Friend => $this->friendCents,
        };
    }

    public static function hoursCents(float $hours, QuoteTariff $tariff): int
    {
        return (int) round($hours * $tariff->hourlyRateCents());
    }
}
