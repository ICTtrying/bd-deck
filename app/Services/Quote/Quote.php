<?php

namespace App\Services\Quote;

use App\Enums\QuoteProjectType;
use App\Enums\QuoteTariff;
use App\Support\Money;

final readonly class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<RecurringLine>  $recurring
     * @param  list<string>  $notes  Aandachtspunten voor jezelf, niet voor de klant.
     * @param  list<string>  $excluded  Wat niet in de prijs zit; gaat wel mee in de offertetekst.
     */
    public function __construct(
        public QuoteProjectType $type,
        public string $title,
        public array $lines,
        public array $recurring,
        public array $notes,
        public array $excluded,
    ) {}

    public function totalCents(QuoteTariff $tariff): int
    {
        return array_sum(array_map(fn (QuoteLine $line): int => $line->cents($tariff), $this->lines));
    }

    public function vatCents(QuoteTariff $tariff): int
    {
        return (int) round($this->totalCents($tariff) * (int) config('quote.vat_percentage') / 100);
    }

    public function totalInclVatCents(QuoteTariff $tariff): int
    {
        return $this->totalCents($tariff) + $this->vatCents($tariff);
    }

    public function totalHours(): float
    {
        return array_sum(array_map(fn (QuoteLine $line): float => $line->hours, $this->lines));
    }

    /**
     * Wat de vriendenprijs scheelt ten opzichte van het standaardtarief.
     */
    public function friendDiscountCents(): int
    {
        return $this->totalCents(QuoteTariff::Standard) - $this->totalCents(QuoteTariff::Friend);
    }

    public function monthlyCents(): int
    {
        return array_sum(array_map(fn (RecurringLine $line): int => $line->monthlyCents, $this->recurring));
    }

    public function yearlyCents(): int
    {
        return array_sum(array_map(fn (RecurringLine $line): int => $line->yearlyCents(), $this->recurring));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [] && $this->recurring === [];
    }

    /**
     * Platte tekst om in een mail te plakken. Bewust altijd Nederlands: dit gaat naar de klant, ongeacht de taal van de app.
     */
    public function toText(QuoteTariff $tariff): string
    {
        $text = ['Offerte: '.$this->title];

        if ($this->lines !== []) {
            $text[] = '';
            $text[] = 'Eenmalig';

            foreach ($this->lines as $line) {
                $text[] = '- '.$line->label.': '.Money::format($line->cents($tariff));
            }

            $text[] = '';
            $text[] = 'Totaal excl. btw: '.Money::format($this->totalCents($tariff));
            $text[] = 'Btw '.config('quote.vat_percentage').'%: '.Money::format($this->vatCents($tariff));
            $text[] = 'Totaal incl. btw: '.Money::format($this->totalInclVatCents($tariff));
        }

        if ($this->recurring !== []) {
            $text[] = '';
            $text[] = 'Per maand (excl. btw)';

            foreach ($this->recurring as $line) {
                $text[] = '- '.$line->label.': '.Money::format($line->monthlyCents);

                if ($line->billedMonthsPerYear < 12) {
                    $text[] = '  Bij jaarbetaling vooraf: '.Money::format($line->yearlyCents()).' per jaar';
                }
            }
        }

        if ($this->excluded !== []) {
            $text[] = '';
            $text[] = 'Niet inbegrepen';

            foreach ($this->excluded as $item) {
                $text[] = '- '.$item;
            }
        }

        return implode("\n", $text);
    }
}
