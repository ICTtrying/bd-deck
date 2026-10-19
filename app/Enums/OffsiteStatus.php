<?php

namespace App\Enums;

enum OffsiteStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Uploading => __('Wordt geüpload'),
            self::Uploaded => __('In de cloud'),
            self::Failed => __('Mislukt'),
            self::Deleted => __('Alleen lokaal'),
        };
    }

    /**
     * Kleurvariant van de status-badge.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Uploading => 'info',
            self::Uploaded => 'success',
            self::Failed => 'danger',
            self::Deleted => 'neutral',
        };
    }

    public function isBusy(): bool
    {
        return in_array($this, [self::Pending, self::Uploading], true);
    }
}
