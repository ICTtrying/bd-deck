<?php

namespace App\Services;

use App\Models\Site;
use App\Services\WpOpen\WpOpen;

/**
 * De adressen die "openen" voor de lokale kant van een site oplevert.
 */
final class SiteLinks
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly WpOpen $wpopen,
    ) {}

    public function local(Site $site, string $target): string
    {
        if ($target === 'mailpit') {
            return $this->mailpit($site);
        }

        // Laravel heeft geen vaste beheerpagina: dan gewoon de site
        if ($target === 'local-site' || $site->isLaravel()) {
            return $site->localUrl('/');
        }

        return $this->settings->autoLoginLocal()
            ? $this->wpopen->loginUrl($site->name, live: false)
            : $site->localUrl('/wp-admin/');
    }

    /**
     * Webinterface van Mailpit in DDEV: daar komt alle mail van de lokale site binnen.
     */
    public function mailpit(Site $site): string
    {
        return rtrim((string) ($site->data('mailpit_url') ?: $site->localUrl().':8026'), '/');
    }
}
