<?php

namespace App\Enums;

enum SiteMode: string
{
    case Ssh = 'ssh';
    case Sftp = 'sftp';
    case Local = 'local';

    /**
     * Toegangsvormen die je bij een server kunt kiezen; Local betekent juist: geen server.
     *
     * @return list<self>
     */
    public static function remote(): array
    {
        return [self::Ssh, self::Sftp];
    }

    public function label(): string
    {
        return match ($this) {
            self::Ssh => 'SSH',
            self::Sftp => __('Alleen SFTP'),
            self::Local => __('Alleen lokaal'),
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
