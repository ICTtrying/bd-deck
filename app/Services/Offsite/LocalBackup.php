<?php

namespace App\Services\Offsite;

use Carbon\CarbonImmutable;

/**
 * Een back-up-map van wpopen op deze laptop: <back-ups>/<slug>/<id>/.
 */
final readonly class LocalBackup
{
    public function __construct(
        public string $slug,
        public string $id,
        public string $path,
        public string $siteName,
        public string $kind,
        public CarbonImmutable $createdAt,
        public int $lastModified,
    ) {}
}
