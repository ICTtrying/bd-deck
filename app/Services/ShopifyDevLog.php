<?php

namespace App\Services;

use App\Models\Site;

/**
 * Leest het log van de dev server rechtstreeks uit de statusmap van wpopen, zodat het scherm elke paar
 * seconden kan verversen zonder telkens een script te starten. Het script maskeert het storefront-wachtwoord al.
 */
final class ShopifyDevLog
{
    private const int MAX_BYTES = 65536;

    public function __construct(private readonly AppSettings $settings) {}

    public function path(Site $site): string
    {
        return $this->settings->homeDirectory().'/.config/wpsites/shopify/'.$site->data('slug', $site->name).'/dev.log';
    }

    /**
     * De laatste regels van het log; leeg als de dev server nog nooit gestart is.
     *
     * @return list<string>
     */
    public function tail(Site $site, int $lines = 40): array
    {
        $path = $this->path($site);

        if (! is_file($path) || ($size = filesize($path)) === false || $size === 0) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - self::MAX_BYTES));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        // kleurcodes en spinner-tekens van de CLI maken het log onleesbaar
        $chunk = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $chunk) ?? $chunk;
        $rows = array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r", "\n", $chunk))), fn (string $line): bool => trim($line) !== ''));

        return array_slice($rows, -$lines);
    }
}
