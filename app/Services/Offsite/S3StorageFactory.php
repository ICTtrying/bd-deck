<?php

namespace App\Services\Offsite;

/**
 * Maakt de S3-client bij de opgeslagen instellingen; in tests te vervangen door kleinere delen.
 */
final readonly class S3StorageFactory
{
    public function __construct(private int $partSize = 16 * 1024 * 1024) {}

    public function make(OffsiteConfig $config): S3Storage
    {
        return new S3Storage(
            endpoint: $config->endpoint,
            region: $config->region,
            bucket: $config->bucket,
            accessKeyId: $config->accessKeyId,
            secretAccessKey: (string) $config->secretAccessKey,
            virtualHosted: $config->provider->usesVirtualHostedStyle(),
            partSize: $this->partSize,
        );
    }
}
