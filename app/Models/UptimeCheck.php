<?php

namespace App\Models;

use Database\Factories\UptimeCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'is_up', 'status_code', 'response_ms', 'error', 'checked_at'])]
class UptimeCheck extends Model
{
    /** @use HasFactory<UptimeCheckFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * Hoe lang metingen bewaard blijven; ook het venster voor het uptimepercentage.
     */
    public const RETENTION_DAYS = 30;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_up' => 'boolean',
            'status_code' => 'integer',
            'response_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    #[Scope]
    protected function since(Builder $query, \DateTimeInterface $moment): void
    {
        $query->where('checked_at', '>=', $moment);
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('checked_at')->orderByDesc('id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
