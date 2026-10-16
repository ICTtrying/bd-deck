<?php

namespace App\Services;

use App\Enums\UptimeStatus;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class UptimeMonitor
{
    /**
     * Een enkele misser kan een haperende verbinding zijn; pas bij twee op rij melden we een storing.
     */
    private const FAILURES_BEFORE_DOWN = 2;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly DesktopNotifier $notifier,
    ) {}

    /**
     * Sites die nu gemeten moeten worden.
     *
     * @return Collection<int, Site>
     */
    public function due(): Collection
    {
        if (! $this->settings->uptimeEnabled()) {
            return new Collection;
        }

        $interval = $this->settings->uptimeIntervalMinutes();

        return Site::query()->where('uptime_enabled', true)->orderBy('id')->get()
            ->filter(fn (Site $site): bool => $site->isMonitored() && $site->isDueForUptimeCheck($interval))
            ->values();
    }

    /**
     * Meet alle sites tegelijk, zodat een trage site de rest niet ophoudt.
     *
     * @param  Collection<int, Site>  $sites
     */
    public function check(Collection $sites): int
    {
        $sites = $sites->filter(fn (Site $site): bool => $site->hasLiveUrl())->values();

        if ($sites->isEmpty()) {
            return 0;
        }

        $responses = Http::pool(function (Pool $pool) use ($sites): array {
            return $sites->map(fn (Site $site) => $pool->as((string) $site->id)
                ->withUserAgent('BD-Deck-Uptime/1.0')
                ->connectTimeout(5)
                ->timeout(15)
                ->get($site->liveUrl('/')))->all();
        });

        foreach ($sites as $site) {
            $this->record($site, $this->measure($responses[(string) $site->id] ?? null));
        }

        return $sites->count();
    }

    /**
     * Zonder meting hoort een site ook geen openstaande storing te houden.
     */
    public function stopMonitoring(Site $site): void
    {
        DB::transaction(function () use ($site): void {
            $site->uptimeIncidents()->ongoing()->update(['ended_at' => now()]);
            $site->update([
                'uptime_enabled' => false, 'uptime_status' => null, 'uptime_failures' => 0, 'uptime_status_since' => null,
            ]);
        });
    }

    /**
     * @return array{is_up: bool, status_code: int|null, response_ms: int|null, error: string|null}
     */
    private function measure(mixed $response): array
    {
        if ($response instanceof Response) {
            $seconds = $response->transferStats?->getTransferTime();
            $up = ! $this->isFailureStatus($response->status());

            return [
                'is_up' => $up,
                'status_code' => $response->status(),
                'response_ms' => $seconds === null ? null : (int) round($seconds * 1000),
                'error' => $up ? null : 'HTTP '.$response->status(),
            ];
        }

        $message = $response instanceof ConnectionException || $response instanceof \Throwable ? $response->getMessage() : __('Geen antwoord');

        return [
            'is_up' => false,
            'status_code' => null,
            'response_ms' => null,
            'error' => Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 200),
        ];
    }

    /**
     * 401, 403 en 429 betekenen dat de server leeft (wachtwoordbeveiliging, firewall of rate limit);
     * alles vanaf 400 daarbuiten is een kapotte pagina.
     */
    private function isFailureStatus(int $status): bool
    {
        return $status >= 400 && ! in_array($status, [401, 403, 429], true);
    }

    /**
     * @param  array{is_up: bool, status_code: int|null, response_ms: int|null, error: string|null}  $result
     */
    private function record(Site $site, array $result): void
    {
        $now = now();
        $wasDown = $site->uptime === UptimeStatus::Down;
        $recoveredAfter = null;
        $wentDownBecause = null;

        DB::transaction(function () use ($site, $result, $now, $wasDown, &$recoveredAfter, &$wentDownBecause): void {
            $site->uptimeChecks()->create([...$result, 'checked_at' => $now]);

            if ($result['is_up']) {
                $site->fill(['uptime_failures' => 0, 'uptime_checked_at' => $now]);

                if ($site->uptime !== UptimeStatus::Up) {
                    $site->fill(['uptime_status' => UptimeStatus::Up->value, 'uptime_status_since' => $now]);
                }

                $site->save();

                if ($wasDown) {
                    $incident = $site->uptimeIncidents()->ongoing()->latestFirst()->first();
                    $incident?->update(['ended_at' => $now]);
                    $recoveredAfter = $incident?->durationInMinutes() ?? 0;
                }

                return;
            }

            $failures = $site->uptime_failures + 1;
            $site->fill(['uptime_failures' => $failures, 'uptime_checked_at' => $now]);

            if ($failures >= self::FAILURES_BEFORE_DOWN && ! $wasDown) {
                // de storing begon bij de eerste misser van deze reeks, niet bij de bevestiging
                $started = $site->uptimeChecks()->latestFirst()->limit($failures)->get()->last()?->checked_at ?? $now;

                $site->fill(['uptime_status' => UptimeStatus::Down->value, 'uptime_status_since' => $started]);
                $site->uptimeIncidents()->create(['reason' => $result['error'], 'started_at' => $started]);
                $wentDownBecause = $result['error'];
            }

            $site->save();
        });

        if ($recoveredAfter !== null) {
            $this->notifier->siteRecovered($site, $recoveredAfter);
        }

        if ($wentDownBecause !== null) {
            $this->notifier->siteDown($site, $wentDownBecause);
        }
    }
}
