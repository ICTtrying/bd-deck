<?php

namespace App\Enums;

enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return match ($this) {
            self::System => __('Systeem'),
            self::Light => __('Licht'),
            self::Dark => __('Donker'),
        };
    }
}
