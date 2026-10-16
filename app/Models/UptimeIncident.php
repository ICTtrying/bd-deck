<?php

namespace App\Models;

use Database\Factories\UptimeIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'reason', 'started_at', 'ended_at'])]
class UptimeIncident extends Model
{
    /** @use HasFactory<UptimeIncidentFactory> */
    use HasFactory;

    use MassPrunable;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
    protected function ongoing(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('started_at')->orderByDesc('id');
    }

    public function isOngoing(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Duur in minuten; een lopende storing telt tot nu.
     */
    public function durationInMinutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now(), absolute: true);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNotNull('ended_at')->where('ended_at', '<', now()->subDays(UptimeCheck::RETENTION_DAYS));
    }
}
