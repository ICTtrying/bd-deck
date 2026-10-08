<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * NativePHP stopt zijn queue-workers niet als de app sluit. Bij elke start ruimen we
 * de achtergebleven workers van eerdere sessies op, anders lopen er steeds meer
 * (en verwerken oude workers jobs met oude code).
 */
final class StaleQueueWorkers
{
    public function __construct(private readonly string $procPath = '/proc') {}

    /**
     * @return list<int> gestopte proces-id's
     */
    public function reap(): array
    {
        $stopped = [];

        foreach (glob($this->procPath.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $directory) {
            $pid = (int) basename($directory);
            $command = $this->read($directory.'/cmdline');
            $parentPid = preg_match('/^PPid:\s+(\d+)/m', $this->read($directory.'/status'), $match) ? (int) $match[1] : 0;

            if (! self::isStale($command, $parentPid, $this->read($this->procPath.'/'.$parentPid.'/cmdline'))) {
                continue;
            }

            Process::run(['kill', '-TERM', (string) $pid]);
            $stopped[] = $pid;
        }

        return $stopped;
    }

    /**
     * Een worker van BD Deck waarvan de app weg is: de ouder is dan systemd of init geworden.
     */
    public static function isStale(string $command, int $parentPid, string $parentCommand): bool
    {
        $isNativeWorker = str_contains($command, 'resources/build/php/php')
            && str_contains($command, 'artisan queue:listen')
            && str_contains($command, '--name=');

        $isThisApp = str_contains($command, '.mount_BD') || str_contains($command, 'BD Deck') || str_contains($command, 'bd-deck');

        $isOrphaned = $parentPid === 1 || str_contains($parentCommand, 'systemd') || $parentCommand === '';

        return $isNativeWorker && $isThisApp && $isOrphaned;
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? '' : trim(str_replace("\0", ' ', $contents));
    }
}
