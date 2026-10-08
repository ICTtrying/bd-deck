<?php

namespace App\Models;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\SiteType;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * Verbindingsgegevens staan in de sitelijst van wpopen; dit model bewaart een snapshot
 * daarvan plus wat alleen de app nodig heeft (favorieten, notities, controles).
 */
#[Fillable([
    'name', 'is_favorite', 'notes', 'live_login_user', 'snapshot', 'health', 'health_checked_at',
    'updates', 'updates_checked_at', 'last_activity_at', 'synced_at',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'snapshot' => 'array',
            'health' => 'array',
            'updates' => 'array',
            'health_checked_at' => 'datetime',
            'updates_checked_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * @return HasMany<CommandRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CommandRun::class);
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    #[Scope]
    protected function favorite(Builder $query): void
    {
        $query->where('is_favorite', true);
    }

    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('snapshot->live_host', 'like', $like)
                ->orWhere('snapshot->host', 'like', $like)
                ->orWhere('notes', 'like', $like);
        });
    }

    #[Scope]
    protected function provider(Builder $query, SiteProvider $provider): void
    {
        $query->where('snapshot->provider', $provider->value);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByDesc('is_favorite')->orderBy('name')->orderBy('id');
    }

    public function data(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->snapshot ?? [], $key, $default);
    }

    /**
     * @return Attribute<SiteProvider, never>
     */
    protected function providerType(): Attribute
    {
        return Attribute::get(fn (): SiteProvider => SiteProvider::tryFrom((string) $this->data('provider')) ?? SiteProvider::Other);
    }

    /**
     * @return Attribute<SiteMode, never>
     */
    protected function mode(): Attribute
    {
        return Attribute::get(fn (): SiteMode => SiteMode::tryFrom((string) $this->data('mode')) ?? SiteMode::Ssh);
    }

    /**
     * @return Attribute<SiteType, never>
     */
    protected function type(): Attribute
    {
        return Attribute::get(fn (): SiteType => SiteType::tryFrom((string) $this->data('type')) ?? SiteType::WordPress);
    }

    /**
     * Laravel-sites beheer je in BD Deck alleen op live: SSH, test, cache en artisan.
     */
    public function isLaravel(): bool
    {
        return $this->type === SiteType::Laravel;
    }

    /**
     * Gemaakt met "Nieuwe lokale site": er is (nog) geen live-server.
     */
    public function isLocalOnly(): bool
    {
        return (bool) $this->data('local_only', false) || $this->mode === SiteMode::Local;
    }

    public function isBuilt(): bool
    {
        return (bool) $this->data('built', false);
    }

    public function isRunning(): bool
    {
        return $this->data('ddev_status') === 'running';
    }

    public function liveUrl(string $path = ''): string
    {
        return rtrim((string) ($this->data('live_site_url') ?? 'https://'.$this->data('host')), '/').$path;
    }

    public function localUrl(string $path = ''): string
    {
        return rtrim((string) $this->data('local_url'), '/').$path;
    }

    public function projectDirectory(): string
    {
        return (string) $this->data('project_dir');
    }

    public function wpContentDirectory(): string
    {
        return (string) $this->data('wp_content_dir');
    }

    /**
     * Aantal bestanden en commits dat lokaal klaarstaat maar nog niet live is.
     */
    public function pendingChanges(): int
    {
        return (int) $this->data('git.pending_files', 0) + (int) $this->data('git.dirty', 0);
    }

    /**
     * Samenvatting van de laatste verbindingstest: ok, warning, danger of unknown.
     */
    public function healthTone(): string
    {
        $statuses = collect($this->health ?? [])->pluck('status');

        return match (true) {
            $statuses->isEmpty() => 'unknown',
            $statuses->contains('fail') && $statuses->contains('ok') => 'warning',
            $statuses->contains('fail') => 'danger',
            default => 'success',
        };
    }

    public function updatesCount(): int
    {
        return collect($this->updates ?? [])->sum(fn (mixed $items): int => is_array($items) ? count($items) : 0);
    }
}
