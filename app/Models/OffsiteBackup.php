<?php

namespace App\Models;

use App\Enums\OffsiteStatus;
use Database\Factories\OffsiteBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Een back-up-map van wpopen zoals die (versleuteld) in de cloud staat of komt te staan.
 */
#[Fillable([
    'site_slug',
    'site_name',
    'backup_id',
    'kind',
    'backup_created_at',
    'object_key',
    'size',
    'key_id',
    'status',
    'error',
    'uploaded_at',
])]
class OffsiteBackup extends Model
{
    /** @use HasFactory<OffsiteBackupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'backup_created_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'size' => 'integer',
            'status' => OffsiteStatus::class,
        ];
    }

    #[Scope]
    protected function forSite(Builder $query, string $slug): void
    {
        $query->where('site_slug', $slug);
    }

    #[Scope]
    protected function inCloud(Builder $query): void
    {
        $query->where('status', OffsiteStatus::Uploaded);
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('backup_created_at')->orderByDesc('backup_id');
    }

    public function isEncryptedWith(?string $keyId): bool
    {
        return $this->key_id === null || $this->key_id === $keyId;
    }
}
