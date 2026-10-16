<?php

use App\Enums\UptimeStatus;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\UptimeMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->notifier = Mockery::mock(DesktopNotifier::class)->shouldIgnoreMissing();
    $this->app->instance(DesktopNotifier::class, $this->notifier);
});

function checkNow(Site $site): void
{
    app(UptimeMonitor::class)->check(collect([$site]));
}

it('legt een geslaagde meting vast en zet de site op online', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $site = Site::factory()->create(['name' => 'klant']);

    checkNow($site);

    expect($site->fresh())
        ->uptime->toBe(UptimeStatus::Up)
        ->uptime_failures->toBe(0)
        ->uptime_checked_at->not->toBeNull()
        ->and(UptimeCheck::query()->sole())
        ->is_up->toBeTrue()
        ->status_code->toBe(200);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://klant.nl/');
});

it('meldt een storing pas na twee missers op rij', function (): void {
    Http::fake(['*' => Http::response('kapot', 503)]);
    $site = Site::factory()->create();

    $this->notifier->shouldReceive('siteDown')->once();

    checkNow($site);

    expect($site->fresh()->uptime)->not->toBe(UptimeStatus::Down)
        ->and(UptimeIncident::query()->count())->toBe(0);

    checkNow($site->fresh());

    expect($site->fresh())->uptime->toBe(UptimeStatus::Down)->uptime_failures->toBe(2)
        ->and(UptimeIncident::query()->sole())->reason->toBe('HTTP 503')->ended_at->toBeNull();

    // een derde misser maakt geen tweede storing en geen tweede melding
    checkNow($site->fresh());

    expect(UptimeIncident::query()->count())->toBe(1);
});

it('laat de storing beginnen bij de eerste misser', function (): void {
    Http::fake(['*' => Http::response('kapot', 500)]);
    $site = Site::factory()->create();

    $this->travelTo(now()->subMinutes(3));
    checkNow($site);
    $this->travelBack();
    checkNow($site->fresh());

    expect(UptimeIncident::query()->sole()->started_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(2);
});

it('sluit de storing en meldt het herstel', function (): void {
    $site = Site::factory()->create(['uptime_status' => 'down', 'uptime_failures' => 3, 'uptime_status_since' => now()->subHour()]);
    $incident = UptimeIncident::factory()->ongoing()->for($site)->create(['started_at' => now()->subMinutes(20)]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->notifier->shouldReceive('siteRecovered')->once()->withArgs(fn (Site $reported, int $minutes): bool => $reported->is($site) && $minutes === 20);

    checkNow($site);

    expect($site->fresh())->uptime->toBe(UptimeStatus::Up)->uptime_failures->toBe(0)
        ->and($incident->fresh()->ended_at)->not->toBeNull();
});

it('telt een verbindingsfout als misser met de foutmelding erbij', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host')]);
    $site = Site::factory()->create();

    checkNow($site);

    expect(UptimeCheck::query()->sole())->is_up->toBeFalse()->error->toContain('Could not resolve host');
});

it('ziet een wachtwoordbeveiligde of firewallende site als bereikbaar', function (int $status): void {
    Http::fake(['*' => Http::response('', $status)]);
    $site = Site::factory()->create();

    checkNow($site);

    expect($site->fresh()->uptime)->toBe(UptimeStatus::Up);
})->with([401, 403, 429]);

it('ziet een 404 op de voorpagina als storing', function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    $site = Site::factory()->create();

    checkNow($site);

    expect(UptimeCheck::query()->sole()->is_up)->toBeFalse();
});

it('meet alleen sites die aan de beurt zijn', function (): void {
    $due = Site::factory()->create(['uptime_checked_at' => now()->subMinutes(6)]);
    Site::factory()->create(['uptime_checked_at' => now()->subMinutes(2)]);
    Site::factory()->create(['uptime_checked_at' => null, 'uptime_enabled' => false]);
    Site::factory()->localOnly()->create();
    $retry = Site::factory()->create(['uptime_checked_at' => now()->subMinutes(2), 'uptime_failures' => 1]);

    expect(app(UptimeMonitor::class)->due()->pluck('id')->all())->toEqualCanonicalizing([$due->id, $retry->id]);
});

it('meet niets als de bewaking globaal uit staat', function (): void {
    Site::factory()->create();
    app(AppSettings::class)->update(['uptime_enabled' => false]);

    expect(app(UptimeMonitor::class)->due())->toBeEmpty();
});

it('meet met het gekozen interval', function (): void {
    Site::factory()->create(['uptime_checked_at' => now()->subMinutes(8)]);
    app(AppSettings::class)->update(['uptime_interval_minutes' => 10]);

    expect(app(UptimeMonitor::class)->due())->toBeEmpty();
});

it('stopt de bewaking en sluit een openstaande storing', function (): void {
    $site = Site::factory()->create(['uptime_status' => 'down', 'uptime_failures' => 2]);
    $incident = UptimeIncident::factory()->ongoing()->for($site)->create();

    app(UptimeMonitor::class)->stopMonitoring($site);

    expect($site->fresh())->uptime_enabled->toBeFalse()->uptime->toBe(UptimeStatus::Unknown)
        ->and($incident->fresh()->ended_at)->not->toBeNull();
});

it('meet via het commando alleen wat aan de beurt is, of alles met --all', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    Site::factory()->create(['uptime_checked_at' => now()]);

    Artisan::call('uptime:check');
    expect(UptimeCheck::query()->count())->toBe(0);

    Artisan::call('uptime:check', ['--all' => true]);
    expect(UptimeCheck::query()->count())->toBe(1);
});

it('ruimt oude metingen en afgesloten storingen op', function (): void {
    $site = Site::factory()->create();
    UptimeCheck::factory()->for($site)->create(['checked_at' => now()->subDays(31)]);
    $recent = UptimeCheck::factory()->for($site)->create(['checked_at' => now()->subDays(5)]);
    UptimeIncident::factory()->for($site)->create(['started_at' => now()->subDays(40), 'ended_at' => now()->subDays(40)]);
    $ongoing = UptimeIncident::factory()->ongoing()->for($site)->create(['started_at' => now()->subDays(40)]);

    Artisan::call('model:prune', ['--model' => [UptimeCheck::class, UptimeIncident::class]]);

    expect(UptimeCheck::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(UptimeIncident::query()->pluck('id')->all())->toBe([$ongoing->id]);
});
