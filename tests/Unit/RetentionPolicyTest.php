<?php

use App\Services\Offsite\RetentionPolicy;
use Carbon\CarbonImmutable;

/**
 * @param  list<string>  $moments
 * @return array<string, CarbonImmutable>
 */
function backupsAt(array $moments): array
{
    $backups = [];

    foreach ($moments as $moment) {
        $date = CarbonImmutable::parse($moment);
        $backups[$date->format('Ymd-His').'-push'] = $date;
    }

    return $backups;
}

beforeEach(function (): void {
    // woensdag
    $this->now = CarbonImmutable::parse('2026-10-14 15:00:00');
});

it('houdt alles als er minder back-ups zijn dan de laatste N', function (): void {
    $backups = backupsAt(['2024-01-01 10:00', '2025-03-01 10:00']);

    expect((new RetentionPolicy(keepLast: 3, daily: 0, weekly: 0, monthly: 0))->prune($backups, $this->now))->toBe([]);
});

it('houdt alleen de laatste N als dag, week en maand uit staan', function (): void {
    $backups = backupsAt(['2026-10-14 09:00', '2026-10-14 10:00', '2026-10-13 10:00', '2026-10-12 10:00', '2026-10-01 10:00']);

    expect((new RetentionPolicy(keepLast: 2, daily: 0, weekly: 0, monthly: 0))->keep($backups, $this->now))
        ->toBe(['20261014-100000-push', '20261014-090000-push']);
});

it('houdt de nieuwste per dag binnen het aantal dagen', function (): void {
    $backups = backupsAt([
        '2026-10-14 08:00', '2026-10-14 12:00',
        '2026-10-13 09:00', '2026-10-13 18:00',
        '2026-10-12 11:00',
        '2026-10-08 11:00', // net buiten 7 dagen (8 t/m 14 = 7 dagen telt de 8e mee)
        '2026-10-07 11:00', // buiten de periode
    ]);

    expect((new RetentionPolicy(keepLast: 1, daily: 7, weekly: 0, monthly: 0))->keep($backups, $this->now))
        ->toBe(['20261014-120000-push', '20261013-180000-push', '20261012-110000-push', '20261008-110000-push']);
});

it('houdt de nieuwste per ISO-week, met maandag als begin', function (): void {
    $backups = backupsAt([
        '2026-10-12 10:00', // maandag deze week
        '2026-10-11 23:00', // zondag vorige week
        '2026-10-05 08:00', // maandag vorige week
        '2026-09-30 10:00', // twee weken terug
        '2026-09-16 10:00', // vier weken terug: buiten 3 weken
    ]);

    expect((new RetentionPolicy(keepLast: 1, daily: 0, weekly: 3, monthly: 0))->keep($backups, $this->now))
        ->toBe(['20261012-100000-push', '20261011-230000-push', '20260930-100000-push']);
});

it('houdt de nieuwste per maand en telt over de jaargrens', function (): void {
    $now = CarbonImmutable::parse('2026-02-10 12:00');
    $backups = backupsAt([
        '2026-02-01 10:00',
        '2026-01-31 10:00', '2026-01-02 10:00',
        '2025-12-31 23:59', '2025-12-01 10:00',
        '2025-11-15 10:00', // buiten 3 maanden
    ]);

    expect((new RetentionPolicy(keepLast: 1, daily: 0, weekly: 0, monthly: 3))->keep($backups, $now))
        ->toBe(['20260201-100000-push', '20260131-100000-push', '20251231-235900-push']);
});

it('combineert alle regels en verwijdert de rest', function (): void {
    $moments = [];
    // elke dag twee back-ups, een jaar lang
    for ($day = 0; $day < 365; $day++) {
        $date = $this->now->subDays($day);
        $moments[] = $date->setTime(9, 0)->toDateTimeString();
        $moments[] = $date->setTime(17, 0)->toDateTimeString();
    }
    $backups = backupsAt($moments);

    $policy = new RetentionPolicy(keepLast: 3, daily: 7, weekly: 4, monthly: 6);
    $keep = $policy->keep($backups, $this->now);
    $prune = $policy->prune($backups, $this->now);

    // 3 laatste (vandaag 17 en 9 uur, gisteren 17 uur) + 6 andere dagen + oudere weken + oudere maanden
    expect($keep)->toContain('20261014-170000-push', '20261014-090000-push', '20261013-170000-push', '20261008-170000-push')
        ->not->toContain('20261007-090000-push')
        ->and(count($keep) + count($prune))->toBe(730)
        ->and(array_intersect($keep, $prune))->toBe([])
        ->and(count($keep))->toBeLessThanOrEqual(3 + 7 + 4 + 6)
        ->and($keep)->toContain('20260531-170000-push') // nieuwste van mei, de oudste maand binnen 6 maanden
        ->and($keep)->not->toContain('20260430-170000-push', '20260501-170000-push');
});

it('verwijdert nooit alles, ook niet na een lange pauze', function (): void {
    $backups = backupsAt(['2024-01-01 10:00', '2024-01-02 10:00', '2024-01-03 10:00', '2024-01-04 10:00']);

    expect((new RetentionPolicy(keepLast: 2))->keep($backups, $this->now))->toBe(['20240104-100000-push', '20240103-100000-push']);
});

it('kiest bij gelijke tijden een vaste back-up', function (): void {
    $moment = CarbonImmutable::parse('2026-10-14 10:00');
    $backups = ['20261014-100000-push' => $moment, '20261014-100000-manual' => $moment];

    expect((new RetentionPolicy(keepLast: 1, daily: 0, weekly: 0, monthly: 0))->keep($backups, $this->now))->toBe(['20261014-100000-push'])
        ->and((new RetentionPolicy(keepLast: 1, daily: 0, weekly: 0, monthly: 0))->keep(array_reverse($backups, true), $this->now))->toBe(['20261014-100000-push']);
});

it('rekent in de tijdzone van nu, niet die van de back-up', function (): void {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'Europe/Amsterdam');
    // 23:30 UTC op de 13e is 01:30 op de 14e in Amsterdam: zelfde dag als de andere
    $backups = [
        'a' => CarbonImmutable::parse('2026-10-13 23:30', 'UTC'),
        'b' => CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Amsterdam'),
    ];

    expect((new RetentionPolicy(keepLast: 1, daily: 2, weekly: 0, monthly: 0))->keep($backups, $now))->toBe(['b']);
});
