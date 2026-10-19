<?php

namespace App\Services\Offsite;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Welke cloud-back-ups blijven staan: altijd de nieuwste N, plus de nieuwste per dag, week en maand
 * binnen de gekozen periode. Puur rekenwerk, zodat het los te testen is.
 */
final readonly class RetentionPolicy
{
    public function __construct(
        public int $keepLast = 3,
        public int $daily = 7,
        public int $weekly = 4,
        public int $monthly = 6,
    ) {}

    /**
     * @param  array<string, CarbonInterface>  $backups  back-up-ID => moment van maken
     * @return list<string> ID's die blijven, nieuwste eerst
     */
    public function keep(array $backups, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $sorted = $this->newestFirst($backups, $now);
        $keep = array_fill_keys(array_slice(array_keys($sorted), 0, max(0, $this->keepLast)), true);

        $periods = [
            [$this->daily, fn (CarbonImmutable $date): string => $date->format('Y-m-d'), $now->startOfDay()->subDays($this->daily - 1)],
            [$this->weekly, fn (CarbonImmutable $date): string => $date->format('o-W'), $now->startOfWeek(CarbonInterface::MONDAY)->subWeeks($this->weekly - 1)],
            [$this->monthly, fn (CarbonImmutable $date): string => $date->format('Y-m'), $now->startOfMonth()->subMonthsNoOverflow($this->monthly - 1)],
        ];

        foreach ($periods as [$count, $bucket, $since]) {
            if ($count < 1) {
                continue;
            }

            $seen = [];

            foreach ($sorted as $id => $created) {
                if ($created->lessThan($since)) {
                    break;
                }

                $period = $bucket($created);

                // gesorteerd van nieuw naar oud: de eerste in een periode is de nieuwste
                if (! isset($seen[$period])) {
                    $seen[$period] = true;
                    $keep[$id] = true;
                }
            }
        }

        return array_values(array_filter(array_keys($sorted), fn (string $id): bool => isset($keep[$id])));
    }

    /**
     * @param  array<string, CarbonInterface>  $backups
     * @return list<string> ID's die weg mogen
     */
    public function prune(array $backups, CarbonInterface $now): array
    {
        $keep = array_flip($this->keep($backups, $now));

        return array_values(array_filter(array_keys($this->newestFirst($backups, CarbonImmutable::instance($now))), fn (string $id): bool => ! isset($keep[$id])));
    }

    /**
     * De regels in gewone taal, bijvoorbeeld voor onder de invoervelden.
     */
    public function describe(): string
    {
        $first = trans_choice('Altijd de laatste back-up|Altijd de laatste :count back-ups', max(1, $this->keepLast));

        $rest = array_values(array_filter([
            $this->daily > 0 ? trans_choice('één per dag voor :count dag|één per dag voor :count dagen', $this->daily) : null,
            $this->weekly > 0 ? trans_choice('één per week voor :count week|één per week voor :count weken', $this->weekly) : null,
            $this->monthly > 0 ? trans_choice('één per maand voor :count maand|één per maand voor :count maanden', $this->monthly) : null,
        ]));

        if ($rest === []) {
            return $first.'.';
        }

        $last = array_pop($rest);
        $list = $rest === [] ? $last : __(':list en :last', ['list' => implode(', ', $rest), 'last' => $last]);

        return __(':first, plus :rest.', ['first' => $first, 'rest' => $list]);
    }

    /**
     * @param  array<string, CarbonInterface>  $backups
     * @return array<string, CarbonImmutable>
     */
    private function newestFirst(array $backups, CarbonImmutable $now): array
    {
        $sorted = array_map(fn (CarbonInterface $date): CarbonImmutable => CarbonImmutable::instance($date)->setTimezone($now->getTimezone()), $backups);

        // vaste volgorde bij gelijke tijden: anders zou een andere back-up kunnen verdwijnen dan getoond
        uksort($sorted, fn (string|int $a, string|int $b): int => [$sorted[$b]->getTimestamp(), (string) $b] <=> [$sorted[$a]->getTimestamp(), (string) $a]);

        return $sorted;
    }
}
