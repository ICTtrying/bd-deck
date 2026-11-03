<?php

namespace App\Livewire\Calculator;

use App\Enums\QuoteManagement;
use App\Enums\QuotePlatform;
use App\Enums\QuoteProjectType;
use App\Enums\QuoteTariff;
use App\Services\Quote\Quote;
use App\Services\Quote\QuoteCalculator;
use App\Services\Quote\QuoteInput;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Calculator')]
class Index extends Component
{
    public QuoteProjectType $type = QuoteProjectType::Website;

    public QuoteTariff $tariff = QuoteTariff::Standard;

    // Getalvelden zijn nullable: een leeg invoerveld wordt door Livewire null, en dat rekent als 0.

    /** 'auto' kiest het kleinste pakket dat bij het aantal pagina's past. */
    public string $package = 'auto';

    public ?int $pages = 3;

    public ?int $extraForms = 0;

    public ?int $extraRounds = 0;

    public ?int $contentPages = 0;

    public bool $replaceLogo = false;

    public ?int $explanationHalfHours = 0;

    public ?float $otherHours = 0;

    public string $otherDescription = '';

    public QuotePlatform $platform = QuotePlatform::WooCommerce;

    public ?int $products = 20;

    public bool $hasVariants = false;

    public bool $multipleShippingZones = false;

    public bool $discountCodes = false;

    public bool $extraPaymentMethod = false;

    public bool $includeIntake = true;

    public bool $includePrototype = false;

    public ?float $buildHours = 0;

    public QuoteManagement $management = QuoteManagement::None;

    public bool $yearlyBilling = false;

    public bool $webAppHosting = false;

    #[Computed]
    public function quote(): Quote
    {
        return app(QuoteCalculator::class)->calculate(new QuoteInput(
            type: $this->type,
            package: $this->package === 'auto' ? null : $this->package,
            pages: $this->pages ?? 0,
            extraForms: $this->extraForms ?? 0,
            extraRounds: $this->extraRounds ?? 0,
            contentPages: $this->contentPages ?? 0,
            replaceLogo: $this->replaceLogo,
            explanationHalfHours: $this->explanationHalfHours ?? 0,
            otherHours: $this->otherHours ?? 0,
            otherDescription: $this->otherDescription,
            platform: $this->platform,
            products: $this->products ?? 0,
            hasVariants: $this->hasVariants,
            multipleShippingZones: $this->multipleShippingZones,
            discountCodes: $this->discountCodes,
            extraPaymentMethod: $this->extraPaymentMethod,
            includeIntake: $this->includeIntake,
            includePrototype: $this->includePrototype,
            buildHours: $this->buildHours ?? 0,
            management: $this->management,
            yearlyBilling: $this->yearlyBilling,
            webAppHosting: $this->webAppHosting,
        ));
    }

    /**
     * Pagina's beginnen per soort opnieuw (een webshop erft anders de pagina's van de website), en een webapp begint op de standaard bouwuren.
     */
    public function selectType(string $type): void
    {
        $previous = $this->type;
        $this->type = QuoteProjectType::tryFrom($type) ?? $this->type;

        if ($this->type !== $previous) {
            $this->pages = $this->type->defaultPages();
        }

        if ($this->type === QuoteProjectType::WebApp && (float) $this->buildHours === 0.0) {
            $this->buildHours = (float) config('quote.webapp.build_default_hours');
        }
    }

    public function render(): View
    {
        return view('livewire.calculator.index', [
            'projectTypes' => QuoteProjectType::cases(),
            'websitePackages' => (array) config('quote.website_packages'),
        ]);
    }
}
