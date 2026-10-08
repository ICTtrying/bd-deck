<?php

namespace App\Enums;

enum SiteMode: string
{
    case Ssh = 'ssh';
    case Sftp = 'sftp';

    public function label(): string
    {
        return match ($this) {
            self::Ssh => 'SSH',
            self::Sftp => __('Alleen SFTP'),
        };
    }

    /**
     * Zonder shell is er geen WP-CLI op live; acties lopen dan via een tijdelijk PHP-bestand.
     */
    public function hasShell(): bool
    {
        return $this === self::Ssh;
    }
}
