<?php

namespace App\Services\WpOpen;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Houdt de sites-tabel gelijk aan de sitelijst van wpopen (de bron van waarheid).
 */
final class SiteRegistry
{
    public function __construct(private readonly WpOpen $wpopen) {}

    public function sync(): int
    {
        $sites = collect($this->wpopen->sites())->filter(fn (mixed $site): bool => is_array($site) && isset($site['name']));

        DB::transaction(function () use ($sites): void {
            foreach ($sites as $data) {
                Site::query()->updateOrCreate(['name' => $data['name']], ['snapshot' => $data, 'synced_at' => now()]);
            }

            // buiten de app om verwijderd (bv. met `wpopen remove`)
            Site::query()->whereNotIn('name', $sites->pluck('name'))->delete();
        });

        return $sites->count();
    }

    public function refresh(Site $site): ?Site
    {
        $data = $this->wpopen->site($site->name);

        if ($data === null) {
            $site->delete();

            return null;
        }

        $site->update(['snapshot' => $data, 'synced_at' => now()]);

        return $site;
    }

    /**
     * @param  list<array{key: string, status: string, label: string, detail: string}>  $checks
     */
    public function recordHealth(Site $site, array $checks): void
    {
        $site->update(['health' => $checks, 'health_checked_at' => now()]);
    }

    /**
     * @param  array{core?: list<array<string, mixed>>, plugins?: list<array<string, mixed>>, themes?: list<array<string, mixed>>}  $updates
     */
    public function recordUpdates(Site $site, array $updates): void
    {
        $site->update([
            'updates' => [
                'core' => $updates['core'] ?? [],
                'plugins' => $updates['plugins'] ?? [],
                'themes' => $updates['themes'] ?? [],
            ],
            'updates_checked_at' => now(),
        ]);
    }
}
