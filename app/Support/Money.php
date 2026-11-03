<?php

namespace App\Support;

final class Money
{
    /**
     * Eurocenten als "€ 1.095" of "€ 17,50"; hele bedragen zonder decimalen.
     */
    public static function format(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;
        $formatted = number_format(abs($cents) / 100, $decimals, ',', '.');

        return ($cents < 0 ? '-' : '').'€ '.$formatted;
    }
}
