<?php

namespace App\Enums;

enum OffsiteProvider: string
{
    case BackblazeB2 = 'backblaze-b2';
    case AmazonS3 = 'amazon-s3';
    case CloudflareR2 = 'cloudflare-r2';
    case Wasabi = 'wasabi';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BackblazeB2 => 'Backblaze B2',
            self::AmazonS3 => 'Amazon S3',
            self::CloudflareR2 => 'Cloudflare R2',
            self::Wasabi => 'Wasabi',
            self::Other => __('Ander (S3-compatibel)'),
        };
    }

    public function defaultRegion(): string
    {
        return match ($this) {
            self::BackblazeB2 => 'eu-central-003',
            self::AmazonS3, self::Wasabi => 'eu-central-1',
            self::CloudflareR2 => 'auto',
            self::Other => 'us-east-1',
        };
    }

    /**
     * Adres van de S3-API; leeg als het iets bevat dat alleen de gebruiker weet (zoals het R2-account-ID).
     */
    public function endpointFor(string $region): string
    {
        $region = $region !== '' ? $region : $this->defaultRegion();

        return match ($this) {
            self::BackblazeB2 => "https://s3.{$region}.backblazeb2.com",
            self::AmazonS3 => "https://s3.{$region}.amazonaws.com",
            self::Wasabi => "https://s3.{$region}.wasabisys.com",
            self::CloudflareR2, self::Other => '',
        };
    }

    public function endpointPlaceholder(): string
    {
        return match ($this) {
            self::CloudflareR2 => 'https://<account-id>.r2.cloudflarestorage.com',
            self::Other => 'https://s3.voorbeeld.nl',
            default => $this->endpointFor($this->defaultRegion()),
        };
    }

    /**
     * Waar je de sleutels vindt, in de woorden van het dashboard van de dienst.
     */
    public function hint(): string
    {
        return match ($this) {
            self::BackblazeB2 => __('In Backblaze: Application Keys → Add a New Application Key, alleen voor deze bucket. De keyID is de access key ID, de applicationKey de geheime sleutel. De regio staat in het endpoint bij je bucket.'),
            self::AmazonS3 => __('In de AWS-console: IAM → Users → Security credentials → Create access key. Geef die gebruiker alleen rechten op deze bucket.'),
            self::CloudflareR2 => __('In Cloudflare: R2 → Manage API tokens → Create API token met Object Read & Write. Het endpoint met je account-ID staat bij de bucket onder Settings.'),
            self::Wasabi => __('In Wasabi: Access Keys → Create New Access Key. De regio staat bij je bucket.'),
            self::Other => __('Bijvoorbeeld MinIO, Hetzner of DigitalOcean Spaces. Endpoint, regio en sleutels vind je in het dashboard of de documentatie van je opslag.'),
        };
    }

    /**
     * Alleen AWS werkt met bucket.s3.regio-adressen; de rest gebruikt endpoint/bucket.
     */
    public function usesVirtualHostedStyle(): bool
    {
        return $this === self::AmazonS3;
    }
}
