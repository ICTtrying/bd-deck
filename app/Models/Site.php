<?php

namespace App\Models;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\SiteType;
use App\Enums\UptimeStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Verbindingsgegevens staan in de sitelijst van wpopen; dit model bewaart een snapshot
 * daarvan plus wat alleen de app nodig heeft (favorieten, notities, controles).
 */
#[Fillable([
    'name', 'is_favorite', 'notes', 'live_login_user', 'snapshot', 'health', 'health_checked_at',
    'updates', 'updates_checked_at', 'last_activity_at', 'synced_at',
    'uptime_enabled', 'uptime_status', 'uptime_failures', 'uptime_status_since', 'uptime_checked_at',
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
            'uptime_enabled' => 'boolean',
            'uptime_failures' => 'integer',
            'uptime_status_since' => 'datetime',
            'uptime_checked_at' => 'datetime',
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

    /**
     * @return HasMany<UptimeCheck, $this>
     */
    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    /**
     * @return HasMany<UptimeIncident, $this>
     */
    public function uptimeIncidents(): HasMany
    {
        return $this->hasMany(UptimeIncident::class);
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

    /**
     * Alleen lokale WordPress-sites: een multisite live zetten of ophalen kan BD Deck niet.
     */
    public function supportsMultisite(): bool
    {
        return $this->isLocalOnly() && ! $this->isLaravel();
    }

    public function isMultisite(): bool
    {
        return (bool) $this->data('multisite', false);
    }

    public function localHost(): string
    {
        return (string) parse_url($this->localUrl(), PHP_URL_HOST);
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function subsites(): array
    {
        return $this->data('subsites', []);
    }

    public function isRunning(): bool
    {
        return $this->data('ddev_status') === 'running';
    }

    /**
     * Openbare deellink (ddev share); null als de site niet (meer) gedeeld wordt.
     */
    public function shareUrl(): ?string
    {
        return $this->isShared() ? (string) $this->data('share.url') : null;
    }

    public function shareExpiresAt(): ?Carbon
    {
        $expires = $this->data('share.expires_at');

        // wpopen geeft de tijd met de offset van de computer; tonen doen we in de tijdzone van de app
        return is_string($expires) && $expires !== '' ? Carbon::parse($expires)->setTimezone(config('app.timezone')) : null;
    }

    /**
     * De snapshot kan ouder zijn dan de eindtijd: een verlopen link telt dan al niet meer mee.
     */
    public function isShared(): bool
    {
        return filled($this->data('share.url')) && ($this->shareExpiresAt()?->isFuture() ?? false);
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

    /**
     * @return Attribute<UptimeStatus, never>
     */
    protected function uptime(): Attribute
    {
        return Attribute::get(fn (): UptimeStatus => UptimeStatus::tryFrom((string) $this->uptime_status) ?? UptimeStatus::Unknown)->withoutObjectCaching();
    }

    /**
     * Alleen sites met een live-adres kunnen gemeten worden; een lokale site heeft dat niet.
     */
    public function hasLiveUrl(): bool
    {
        return ! $this->isLocalOnly() && filled($this->data('live_site_url') ?? $this->data('host'));
    }

    public function isMonitored(): bool
    {
        return $this->uptime_enabled && $this->hasLiveUrl();
    }

    /**
     * Na een misser komt de volgende meting snel, zodat een storing vlot bevestigd of weerlegd wordt.
     */
    public function isDueForUptimeCheck(int $intervalMinutes): bool
    {
        if ($this->uptime_checked_at === null) {
            return true;
        }

        $interval = $this->uptime_failures > 0 ? 1 : $intervalMinutes;

        // de scheduler tikt elke minuut; een paar seconden speling voorkomt dat een meting steeds een minuut te laat valt
        return $this->uptime_checked_at->lte(now()->subMinutes($interval)->addSeconds(15));
    }

    /**
     * WordPress vraagt de updates op live op, Laravel kijkt in de lokale Composer- en npm-bestanden.
     */
    public function supportsUpdateCheck(): bool
    {
        return ! $this->isLocalOnly() && ($this->isLaravel() ? $this->isBuilt() : true);
    }

    public function isOffline(): bool
    {
        return $this->isMonitored() && $this->uptime === UptimeStatus::Down;
    }

    /**
     * Uptime over de afgelopen 30 dagen; alleen beschikbaar als de metingen zijn meegeteld (zie scopeWithUptimeCounts).
     */
    public function uptimePercentage(): ?float
    {
        $total = (int) ($this->uptime_checks_count ?? 0);

        return $total === 0 ? null : (int) $this->uptime_up_count / $total * 100;
    }

    /**
     * Uptime in procenten over de afgelopen dagen; leeg zolang er nog niet gemeten is.
     */
    public function uptimePercentageSince(int $days): ?float
    {
        $checks = $this->uptimeChecks()->since(now()->subDays($days));
        $total = (clone $checks)->count();

        return $total === 0 ? null : (clone $checks)->where('is_up', true)->count() / $total * 100;
    }

    /**
     * Gemiddelde reactietijd van de laatste 24 uur, in milliseconden.
     */
    public function averageResponseTime(): ?int
    {
        $average = $this->uptimeChecks()->since(now()->subDay())->where('is_up', true)->avg('response_ms');

        return $average === null ? null : (int) round((float) $average);
    }

    #[Scope]
    protected function withUptimeCounts(Builder $query): void
    {
        $since = now()->subDays(UptimeCheck::RETENTION_DAYS);

        $query->withCount([
            'uptimeChecks' => fn (Builder $checks) => $checks->since($since),
            'uptimeChecks as uptime_up_count' => fn (Builder $checks) => $checks->since($since)->where('is_up', true),
        ]);
    }

    /**
     * Back-up-mappen heten datum-tijd-soort (20261008-160310-push).
     */
    public function lastBackupAt(): ?Carbon
    {
        $id = (string) $this->data('last_backup');

        return preg_match('/^\d{8}-\d{6}/', $id) === 1 ? Carbon::createFromFormat('Ymd-His', substr($id, 0, 15)) ?: null : null;
    }
}
