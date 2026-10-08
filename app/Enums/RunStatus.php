<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Cancelling = 'cancelling';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('In de wachtrij'),
            self::Running => __('Bezig'),
            self::Cancelling => __('Wordt gestopt'),
            self::Succeeded => __('Gelukt'),
            self::Failed => __('Mislukt'),
            self::Cancelled => __('Gestopt'),
        };
    }

    /**
     * Kleurvariant van de status-badge.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Running, self::Cancelling => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Cancelling], true);
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Running, self::Cancelling];
    }
}
