<?php

namespace App\Services\Quote;

use App\Enums\QuoteManagement;
use App\Enums\QuotePlatform;
use App\Enums\QuoteProjectType;
use App\Enums\QuoteTariff;
use App\Support\Money;

/**
 * Rekent een offerte uit op basis van config/quote.php. De tekst die hier ontstaat gaat naar klanten
 * en is daarom vast Nederlands en niet vertaald met __().
 */
final class QuoteCalculator
{
    public function calculate(QuoteInput $input): Quote
    {
        [$lines, $notes] = match ($input->type) {
            QuoteProjectType::Website => $this->website($input),
            QuoteProjectType::Webshop => $this->webshop($input),
            QuoteProjectType::WebApp => $this->webApp($input),
        };

        if ($input->type->usesPackages()) {
            $lines = [...$lines, ...$this->contentExtras($input)];
        }

        if ($input->otherHours > 0) {
            $lines[] = QuoteLine::hourly('Overig werk', null, round($input->otherHours, 2));
        }

        return new Quote(
            type: $input->type,
            title: $this->title($input),
            lines: $lines,
            recurring: $this->recurring($input),
            notes: [...$notes, ...$this->managementNotes($input)],
            excluded: $this->excluded($input),
        );
    }

    /**
     * @return array{0: list<QuoteLine>, 1: list<string>}
     */
    private function website(QuoteInput $input): array
    {
        $pages = max(1, $input->pages ?? $input->type->defaultPages());
        $packages = (array) config('quote.website_packages');
        $key = isset($packages[$input->package]) ? $input->package : $this->smallestWebsitePackage($packages, $pages);
        $package = $packages[$key];

        $lines = [
            QuoteLine::fixed('WordPress-website '.$package['label'], $this->packageDetail($package), (float) $package['hours'], $package['price']),
            ...$this->packageExtras($input, $package, $pages),
        ];

        $notes = [];
        $largest = max(array_column($packages, 'pages'));

        if ($pages > $largest) {
            $notes[] = "Boven {$largest} pagina's is op de site een aparte offerte; hier is "
                .Money::format((int) config('quote.extras.page.price')).' per extra pagina gerekend.';
        }

        return [$lines, $notes];
    }

    /**
     * @return array{0: list<QuoteLine>, 1: list<string>}
     */
    private function webshop(QuoteInput $input): array
    {
        $packages = (array) config('quote.webshop_packages');
        $reasons = $this->webshopGrowthReasons($input, $packages['start']);
        $key = $reasons === [] ? 'start' : 'groei';
        $package = $packages[$key];
        $pages = max(0, $input->pages ?? $input->type->defaultPages());

        $lines = [
            QuoteLine::fixed($package['label'].' ('.$input->platform->label().')', $this->packageDetail($package), (float) $package['hours'], $package['price']),
            ...$this->extraProducts($input, $package),
            ...$this->packageExtras($input, $package, $pages),
        ];

        $notes = ['Prijs is "vanaf": '.$package['label'].' op de site.'];

        if ($reasons !== []) {
            $notes[] = 'Webshop Groei vanwege: '.implode(', ', $reasons).'.';
        }

        if ($input->products >= (int) config('quote.webshop.custom_quote_products')) {
            $notes[] = 'Vanaf '.config('quote.webshop.custom_quote_products').' producten is een aparte offerte verstandig (import, koppelingen).';
        }

        return [$lines, $notes];
    }

    /**
     * @return array{0: list<QuoteLine>, 1: list<string>}
     */
    private function webApp(QuoteInput $input): array
    {
        $config = (array) config('quote.webapp');
        $intake = $config['intake'];
        $prototype = $config['prototype'];
        $build = max(0.0, round($input->buildHours, 2));
        $lines = [];
        $notes = [];

        if ($input->includeIntake) {
            $lines[] = QuoteLine::fixed($intake['label'], 'Twee uur het werkproces doornemen, met schriftelijk advies en vaste prijs voor de bouw', $intake['hours'], $intake['price']);
        }

        if ($input->includePrototype) {
            $lines[] = QuoteLine::fixed($prototype['label'], 'Werkende proefversie met voorbeeldgegevens, maximaal 3 schermen en 1 soort gebruiker', $prototype['hours'], $prototype['price']);
        }

        if ($build > 0) {
            $standard = max((int) $config['build_minimum_price'], QuoteLine::hoursCents($build, QuoteTariff::Standard));
            $lines[] = new QuoteLine('Bouw eerste versie', null, $build, $standard, QuoteLine::hoursCents($build, QuoteTariff::Friend));

            if ($input->includeIntake) {
                $lines[] = new QuoteLine('Intake verrekend met de bouw', null, -$intake['hours'], -$intake['price'], -QuoteLine::hoursCents($intake['hours'], QuoteTariff::Friend));
            }

            if ($standard > QuoteLine::hoursCents($build, QuoteTariff::Standard)) {
                $notes[] = 'Op het standaardtarief geldt voor de bouw een minimum van '.Money::format((int) $config['build_minimum_price']).'.';
            }
        } elseif ($input->includeIntake) {
            $notes[] = 'Na de intake volgt een vaste prijs voor de bouw; die begint meestal bij '.Money::format((int) $config['build_minimum_price']).'.';
        }

        return [$lines, $notes];
    }

    /**
     * @param  array<string, array<string, mixed>>  $packages
     */
    private function smallestWebsitePackage(array $packages, int $pages): string
    {
        foreach ($packages as $key => $package) {
            if ($pages <= $package['pages']) {
                return $key;
            }
        }

        return (string) array_key_last($packages);
    }

    /**
     * Wat de klant vraagt dat Webshop Start niet dekt.
     *
     * @param  array<string, mixed>  $start
     * @return list<string>
     */
    private function webshopGrowthReasons(QuoteInput $input, array $start): array
    {
        return array_keys(array_filter([
            "meer dan {$start['products']} producten" => $input->products > $start['products'],
            'varianten (maat, kleur)' => $input->hasVariants,
            'meerdere verzendzones' => $input->multipleShippingZones,
            'kortingscodes' => $input->discountCodes,
            'extra betaalmethode' => $input->extraPaymentMethod,
            "meer dan {$start['pages']} pagina's naast de shop" => ($input->pages ?? $input->type->defaultPages()) > $start['pages'],
        ]));
    }

    /**
     * Invoertijd voor producten boven wat het pakket al dekt: een schatting, niet van de site.
     *
     * @param  array<string, mixed>  $package
     * @return list<QuoteLine>
     */
    private function extraProducts(QuoteInput $input, array $package): array
    {
        $extra = max(0, $input->products - $package['products']);

        if ($extra === 0) {
            return [];
        }

        $perProduct = (float) config($input->hasVariants ? 'quote.webshop.variant_product_hours' : 'quote.webshop.product_hours');

        return [QuoteLine::hourly(
            "Producten invoeren ({$extra} extra)",
            $input->hasVariants ? 'Inclusief varianten' : null,
            round($extra * $perProduct, 2),
        )];
    }

    /**
     * Pagina's, formulieren en wijzigingsrondes boven wat het pakket bevat.
     *
     * @param  array<string, mixed>  $package
     * @return list<QuoteLine>
     */
    private function packageExtras(QuoteInput $input, array $package, int $pages): array
    {
        return array_values(array_filter([
            $this->extra('page', max(0, $pages - $package['pages'])),
            $this->extra('form', max(0, $input->extraForms)),
            $this->extra('round', max(0, $input->extraRounds)),
        ]));
    }

    /**
     * @return list<QuoteLine>
     */
    private function contentExtras(QuoteInput $input): array
    {
        return array_values(array_filter([
            $this->extra('content', max(0, $input->contentPages)),
            $this->extra('logo', $input->replaceLogo ? 1 : 0),
            $this->extra('explanation', max(0, $input->explanationHalfHours)),
        ]));
    }

    private function extra(string $key, int $count): ?QuoteLine
    {
        if ($count < 1) {
            return null;
        }

        $extra = (array) config("quote.extras.{$key}");

        return QuoteLine::fixed(
            $count > 1 ? "{$extra['label']} ({$count}×)" : $extra['label'],
            null,
            $extra['hours'] * $count,
            $extra['price'] * $count,
        );
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageDetail(array $package): string
    {
        $parts = [];

        if (isset($package['products'])) {
            $parts[] = "tot {$package['products']} producten";
        }

        $parts[] = $package['pages'] === 1 ? 'tot 1 pagina' : "tot {$package['pages']} pagina's";

        if ($package['forms'] > 0) {
            $parts[] = $package['forms'] === 1 ? '1 formulier' : "{$package['forms']} formulieren";
        }

        $parts[] = "{$package['rounds']} wijzigingsrondes";
        $parts[] = "{$package['aftercare_days']} dagen nazorg";

        return implode(', ', $parts);
    }

    /**
     * @return list<RecurringLine>
     */
    private function recurring(QuoteInput $input): array
    {
        if ($input->type === QuoteProjectType::WebApp) {
            return $input->webAppHosting
                ? [new RecurringLine('Hosting en onderhoud Laravel', 'Eigen server, updates, back-ups en 1 uur onderhoud per maand (vanaf)', (int) config('quote.webapp.hosting_price'))]
                : [];
        }

        $price = $input->management->monthlyPriceCents();

        if ($price === null) {
            return [];
        }

        return [new RecurringLine(
            $input->management->label(),
            $input->management === QuoteManagement::Plus
                ? 'Hosting, updates, back-ups, monitoring en twee kleine wijzigingen (samen 30 minuten) per maand'
                : 'Hosting, updates, back-ups en monitoring',
            $price,
            $input->yearlyBilling ? (int) config('quote.yearly_billed_months') : 12,
        )];
    }

    /**
     * @return list<string>
     */
    private function managementNotes(QuoteInput $input): array
    {
        if ($input->type->isShop() && $input->management !== QuoteManagement::Plus) {
            return ['Voor een webshop adviseer je op je site Beheer Plus ('.Money::format((int) config('quote.management.plus.price')).' per maand).'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function excluded(QuoteInput $input): array
    {
        if ($input->type === QuoteProjectType::WebApp) {
            return [];
        }

        $excluded = [$input->type->isShop() ? 'Teksten en productfoto\'s' : 'Teksten en fotografie'];

        $excluded[] = $input->management === QuoteManagement::None ? 'Hosting en domeinnaam' : 'Domeinnaam';

        if ($input->type->isShop()) {
            $excluded[] = 'Kosten van je betaalprovider (zoals Mollie)';
        }

        if ($input->type->isShop() && $input->platform === QuotePlatform::Shopify) {
            $excluded[] = 'Shopify-abonnement';
        } else {
            $excluded[] = 'Enfold-licentie: eenmalig $'.config('quote.enfold_license_dollars').' excl. btw, op jouw naam via ThemeForest';
        }

        return $excluded;
    }

    private function title(QuoteInput $input): string
    {
        return match ($input->type) {
            QuoteProjectType::Website => 'WordPress-website',
            QuoteProjectType::Webshop => 'Webshop in '.$input->platform->label(),
            QuoteProjectType::WebApp => 'Webapplicatie in Laravel',
        };
    }
}
