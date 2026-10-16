<?php

namespace App\Enums;

enum UptimeStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Up => __('Online'),
            self::Down => __('Offline'),
            self::Unknown => __('Nog niet gemeten'),
        };
    }

    /**
     * Kleurvariant van de status-badge.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Down => 'danger',
            self::Unknown => 'neutral',
        };
    }
}
