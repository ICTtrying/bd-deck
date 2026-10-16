<?php

namespace App\Services;

use App\Enums\WpOpenAction;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;

/**
 * Zet de updatecontrole voor meerdere sites tegelijk in de wachtrij.
 */
final class UpdateChecker
{
    /**
     * Na zoveel uur is de laatste controle te oud om nog op te vertrouwen.
     */
    public const STALE_AFTER_HOURS = 24;

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * @return Collection<int, Site>
     */
    public function stale(): Collection
    {
        return $this->checkable()->filter(fn (Site $site): bool => $site->updates_checked_at === null
            || $site->updates_checked_at->lt(now()->subHours(self::STALE_AFTER_HOURS)))->values();
    }

    /**
     * @return Collection<int, Site>
     */
    public function checkable(): Collection
    {
        return Site::query()->ordered()->get()->filter(fn (Site $site): bool => $site->supportsUpdateCheck())->values();
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function queue(Collection $sites): int
    {
        $busy = CommandRun::query()->active()->where('action', WpOpenAction::Updates)->pluck('site_id');

        $sites = $sites->reject(fn (Site $site): bool => $busy->contains($site->id));

        foreach ($sites as $site) {
            $this->runner->queue(WpOpenCommand::updates($site), notify: false);
        }

        return $sites->count();
    }
}
