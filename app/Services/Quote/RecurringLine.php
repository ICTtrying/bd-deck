<?php

namespace App\Services\Quote;

/**
 * Terugkerende kosten per maand, exclusief btw. Vast geprijsd, dus gelijk voor elk tarief.
 */
final readonly class RecurringLine
{
    public function __construct(
        public string $label,
        public ?string $detail,
        public int $monthlyCents,
        public int $billedMonthsPerYear = 12,
    ) {}

    public function yearlyCents(): int
    {
        return $this->monthlyCents * $this->billedMonthsPerYear;
    }
}
