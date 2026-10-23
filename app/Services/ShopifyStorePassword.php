<?php

namespace App\Services;

use App\Enums\WpOpenAction;
use App\Models\Site;

/**
 * Het storefront-wachtwoord van een winkel met wachtwoordpagina staat in de kluis. Het gaat alleen als
 * omgevingsvariabele naar de dev server (SHOPIFY_FLAG_STORE_PASSWORD), nooit als argument en nooit in een log.
 * Ontsleutelen kan alleen in een verzoek met een ontgrendelde kluis, dus dit gebeurt bij het in de wachtrij zetten.
 */
final class ShopifyStorePassword
{
    public const string VARIABLE = 'SHOPIFY_FLAG_STORE_PASSWORD';

    public function __construct(private readonly Vault $vault) {}

    /**
     * @return array<string, string>
     */
    public function environmentFor(?Site $site, WpOpenAction $action): array
    {
        if ($site === null || $action !== WpOpenAction::ShopifyDevStart || ! $site->isShopify()) {
            return [];
        }

        $secret = $site->storePasswordCredential()?->secret;

        return $secret === null ? [] : [self::VARIABLE => $secret->reveal($this->vault)];
    }
}
