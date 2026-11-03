<?php

namespace App\Services\Quote;

use App\Enums\QuoteManagement;
use App\Enums\QuotePlatform;
use App\Enums\QuoteProjectType;

final readonly class QuoteInput
{
    /**
     * @param  string|null  $package  Sleutel uit config/quote.php; null kiest automatisch.
     * @param  int|null  $pages  Website: totaal aantal pagina's. Webshop: pagina's naast de shop. Null is de standaard van het soort.
     */
    public function __construct(
        public QuoteProjectType $type,
        public ?string $package = null,
        public ?int $pages = null,
        public int $extraForms = 0,
        public int $extraRounds = 0,
        public int $contentPages = 0,
        public bool $replaceLogo = false,
        public int $explanationHalfHours = 0,
        public float $otherHours = 0,
        public QuotePlatform $platform = QuotePlatform::WooCommerce,
        public int $products = 0,
        public bool $hasVariants = false,
        public bool $multipleShippingZones = false,
        public bool $discountCodes = false,
        public bool $extraPaymentMethod = false,
        public bool $includeIntake = true,
        public bool $includePrototype = false,
        public float $buildHours = 0,
        public QuoteManagement $management = QuoteManagement::None,
        public bool $yearlyBilling = false,
        public bool $webAppHosting = false,
    ) {}
}
