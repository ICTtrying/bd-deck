<?php

namespace App\Services\Offsite;

use App\Enums\OffsiteProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

/**
 * Instellingen voor back-ups in de cloud, met de geheimen al ontsleuteld.
 *
 * Geheimen staan met Crypt (de sleutel per installatie) in de settings-tabel: de kluis is alleen
 * tijdens een sessie open, maar de wachtrij moet ook uploaden als niemand is ingelogd.
 */
final readonly class OffsiteConfig
{
    public const string DEFAULT_PREFIX = 'bd-deck';

    public function __construct(
        public bool $enabled = false,
        public OffsiteProvider $provider = OffsiteProvider::BackblazeB2,
        public string $endpoint = '',
        public string $region = '',
        public string $bucket = '',
        public string $accessKeyId = '',
        #[SensitiveParameter] public ?string $secretAccessKey = null,
        public string $prefix = self::DEFAULT_PREFIX,
        #[SensitiveParameter] public ?string $passphrase = null,
        public ?string $keyId = null,
        public RetentionPolicy $retention = new RetentionPolicy,
        public bool $cleanupLocal = false,
        public int $cleanupLocalDays = 30,
        public ?CarbonImmutable $lastSyncAt = null,
        public bool $failureNotified = false,
    ) {}

    /**
     * @param  array<string, mixed>  $values  zoals opgeslagen in de setting offsite_backups
     */
    public static function fromArray(array $values): self
    {
        $provider = OffsiteProvider::tryFrom((string) ($values['provider'] ?? '')) ?? OffsiteProvider::BackblazeB2;
        $region = trim((string) ($values['region'] ?? '')) ?: $provider->defaultRegion();

        return new self(
            enabled: (bool) ($values['enabled'] ?? false),
            provider: $provider,
            endpoint: trim((string) ($values['endpoint'] ?? '')) ?: $provider->endpointFor($region),
            region: $region,
            bucket: trim((string) ($values['bucket'] ?? '')),
            accessKeyId: trim((string) ($values['access_key_id'] ?? '')),
            secretAccessKey: self::unseal($values['secret_access_key'] ?? null),
            prefix: trim((string) ($values['prefix'] ?? ''), '/') ?: self::DEFAULT_PREFIX,
            passphrase: self::unseal($values['passphrase'] ?? null),
            keyId: is_string($values['key_id'] ?? null) ? $values['key_id'] : null,
            retention: new RetentionPolicy(
                keepLast: max(1, (int) ($values['keep_last'] ?? 3)),
                daily: max(0, (int) ($values['keep_daily'] ?? 7)),
                weekly: max(0, (int) ($values['keep_weekly'] ?? 4)),
                monthly: max(0, (int) ($values['keep_monthly'] ?? 6)),
            ),
            cleanupLocal: (bool) ($values['cleanup_local'] ?? false),
            cleanupLocalDays: max(1, (int) ($values['cleanup_local_days'] ?? 30)),
            lastSyncAt: is_string($values['last_sync_at'] ?? null) ? CarbonImmutable::parse($values['last_sync_at']) : null,
            failureNotified: (bool) ($values['failure_notified'] ?? false),
        );
    }

    public static function seal(#[SensitiveParameter] string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    /**
     * Een andere installatiesleutel (nieuwe laptop, kopie van de database) maakt het geheim onleesbaar: dan opnieuw invullen.
     */
    private static function unseal(mixed $sealed): ?string
    {
        if (! is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Alles ingevuld om te kunnen uploaden en terughalen.
     */
    public function isComplete(): bool
    {
        return $this->endpoint !== ''
            && $this->bucket !== ''
            && $this->accessKeyId !== ''
            && $this->secretAccessKey !== null
            && $this->passphrase !== null;
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->isComplete();
    }

    public function sitePrefix(string $slug): string
    {
        return $this->prefix.'/'.$slug.'/';
    }

    public function objectKey(string $slug, string $backupId): string
    {
        return $this->sitePrefix($slug).$backupId.'.tar.enc';
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return array_replace(get_object_vars($this), [
            'secretAccessKey' => $this->secretAccessKey === null ? null : '***',
            'passphrase' => $this->passphrase === null ? null : '***',
        ]);
    }
}
