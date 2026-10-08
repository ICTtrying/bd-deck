<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Models\CommandRun;
use App\Services\DesktopNotifier;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunWpOpenCommand implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Een eerste bouw met grote database kan lang duren.
     */
    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, string>  $secretEnvironment
     */
    public function __construct(
        public CommandRun $run,
        public ?string $script = null,
        public array $secretEnvironment = [],
    ) {}

    /**
     * Wachten op een andere actie op dezelfde site telt niet als mislukte poging.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(4);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        if ($this->run->site_id === null || ! $this->run->action->locksSite()) {
            return [];
        }

        return [(new WithoutOverlapping('site-'.$this->run->site_id))->releaseAfter(5)->expireAfter($this->timeout + 300)];
    }

    public function handle(WpOpen $wpopen, SiteRegistry $registry, DesktopNotifier $notifier): void
    {
        $run = $this->run->refresh();

        if ($run->status !== RunStatus::Queued) {
            return;
        }

        $buffer = '';
        $cancelled = false;

        try {
            $process = $wpopen->pending($this->secretEnvironment)
                ->timeout($this->timeout - 60)
                ->start($wpopen->command($run->arguments, $this->script), function (string $type, string $output) use (&$buffer): void {
                    $buffer .= $output;
                });

            $run->markRunning((int) $process->id());
            $flushedAt = $checkedAt = microtime(true);

            while ($process->running()) {
                if ($buffer !== '' && microtime(true) - $flushedAt > 0.4) {
                    $run->appendOutput($buffer);
                    $buffer = '';
                    $flushedAt = microtime(true);
                }

                if (! $cancelled && microtime(true) - $checkedAt > 1) {
                    $checkedAt = microtime(true);

                    if (CommandRun::query()->whereKey($run->id)->value('status') === RunStatus::Cancelling->value) {
                        $cancelled = true;
                        $wpopen->terminate((int) $process->id());
                    }
                }

                usleep(150_000);
            }

            $result = $process->wait();

            if ($buffer !== '') {
                $run->appendOutput($buffer);
            }

            $run->finish((int) $result->exitCode(), $cancelled);
        } catch (Throwable $exception) {
            $run->appendOutput(($buffer !== '' ? $buffer : '')."\n✗ ".$exception->getMessage()."\n");
            $run->finish(1);
        }

        $this->afterRun($run, $registry);
        $notifier->runFinished($run);
    }

    private function afterRun(CommandRun $run, SiteRegistry $registry): void
    {
        $site = $run->site;

        if ($site === null) {
            if (in_array($run->action, [WpOpenAction::AddSite, WpOpenAction::Import, WpOpenAction::InstallScript], true)) {
                rescue(fn (): int => $registry->sync(), report: false);
            }

            return;
        }

        $site->update(['last_activity_at' => now()]);

        if ($run->status === RunStatus::Succeeded) {
            rescue(function () use ($run, $site, $registry): void {
                match ($run->action) {
                    WpOpenAction::Test => $registry->recordHealth($site, (array) WpOpen::decode((string) $run->output)),
                    WpOpenAction::Updates => $registry->recordUpdates($site, (array) WpOpen::decode((string) $run->output)),
                    default => null,
                };
            }, report: false);
        }

        if ($run->action->refreshesSite()) {
            rescue(fn () => $registry->refresh($site), report: false);
        }
    }
}
