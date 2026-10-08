<?php

namespace App\Support;

use App\Services\Vault;

/**
 * Versleuteld geheim zoals het in de database staat; pas leesbaar met een ontgrendelde kluis.
 */
final readonly class SealedSecret
{
    public function __construct(public string $ciphertext) {}

    public function reveal(Vault $vault): string
    {
        return $vault->decrypt($this->ciphertext);
    }
}
