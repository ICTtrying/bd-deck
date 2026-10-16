<?php

use App\Livewire\Dashboard;
use App\Livewire\Settings\Index;
use App\Livewire\Sidebar;
use App\Livewire\Sites\Show;
use App\Livewire\Sites\Uptime;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\Vault;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    Process::fake(['*' => Process::result('[]')]);
    $this->user = User::factory()->owner('wachtwoord-123')->create();
    $this->actingAs($this->user);
    $this->app->instance(DesktopNotifier::class, Mockery::mock(DesktopNotifier::class)->shouldIgnoreMissing());
});

it('toont de uptime-tab met percentages en storingen', function (): void {
    $site = Site::factory()->create([
        'name' => 'klant', 'uptime_status' => 'up', 'uptime_status_since' => now()->subDay(),
    ]);
    UptimeCheck::factory()->for($site)->count(3)->create(['response_ms' => 300]);
    UptimeCheck::factory()->for($site)->down('HTTP 503')->create();
    UptimeIncident::factory()->for($site)->create(['reason' => 'HTTP 503', 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30)]);

    // mount() leest de site opnieuw uit wpopen
    Process::fake(['*' => Process::result(json_encode($site->snapshot))]);

    Livewire::withQueryParams(['tab' => 'uptime'])
        ->test(Show::class, ['site' => $site])
        ->assertSee('75%')
        ->assertSee('300 ms')
        ->assertSee('HTTP 503')
        ->assertSee('30 minuten');
});

it('verbergt de uptime-tab bij een lokale site', function (): void {
    $site = Site::factory()->localOnly()->create();
    Process::fake(['*' => Process::result(json_encode($site->snapshot))]);

    Livewire::test(Show::class, ['site' => $site])->assertSee('Overzicht')->assertDontSee('Uptime');
});

it('meet direct vanaf de uptime-tab', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $site = Site::factory()->create();

    Livewire::test(Uptime::class, ['site' => $site])
        ->assertSee('Nog geen metingen')
        ->call('check')
        ->assertDontSee('Nog geen metingen')
        ->assertSee('Online');

    expect($site->fresh()->uptime_status)->toBe('up');
});

it('zet bewaking per site uit en weer aan', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $site = Site::factory()->create(['uptime_status' => 'down', 'uptime_failures' => 2]);

    Livewire::test(Uptime::class, ['site' => $site])
        ->call('toggle')
        ->assertSee('Bewaking uit');

    expect($site->fresh())->uptime_enabled->toBeFalse()->uptime_status->toBeNull();

    Livewire::test(Uptime::class, ['site' => $site->fresh()])->call('toggle');

    expect($site->fresh())->uptime_enabled->toBeTrue()->uptime_status->toBe('up');
});

it('waarschuwt op het dashboard voor een site die offline is', function (): void {
    Site::factory()->create(['name' => 'storing-nl', 'uptime_status' => 'down', 'uptime_status_since' => now()->subMinutes(12)]);
    Site::factory()->create(['name' => 'gezond-nl', 'uptime_status' => 'up']);
    Site::factory()->create(['name' => 'uit-nl', 'uptime_status' => 'down', 'uptime_enabled' => false]);

    Livewire::test(Dashboard::class)
        ->assertSee('storing-nl is offline')
        ->assertDontSee('gezond-nl is offline')
        ->assertDontSee('uit-nl is offline');
});

it('toont offline in de sitelijst', function (): void {
    Site::factory()->create(['name' => 'storing-nl', 'uptime_status' => 'down']);

    $this->get(route('dashboard'))->assertOk()->assertSee('Offline');
});

it('slaat de uptime-instellingen op en weigert een onbekend interval', function (): void {
    app(Vault::class)->unlock($this->user, 'wachtwoord-123');

    Livewire::test(Index::class)
        ->set('uptimeEnabled', false)
        ->set('uptimeIntervalMinutes', 15)
        ->set('dailyUpdateCheck', false)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(AppSettings::class);
    expect($settings->uptimeEnabled())->toBeFalse()
        ->and($settings->uptimeIntervalMinutes())->toBe(15)
        ->and($settings->dailyUpdateCheck())->toBeFalse();

    Livewire::test(Index::class)->set('uptimeIntervalMinutes', 7)->call('save')->assertHasErrors('uptimeIntervalMinutes');
});

it('toont op de zijbalk een badge met het aantal sites dat offline is', function (): void {
    Site::factory()->create(['uptime_status' => 'down']);
    Site::factory()->create(['uptime_status' => 'down', 'uptime_enabled' => false]);
    Site::factory()->create(['uptime_status' => 'up']);

    $sidebar = Livewire::test(Sidebar::class);

    expect($sidebar->get('attention'))->toBe(['offline' => 1, 'warnings' => 0]);
    $sidebar->assertSee('Uptime & onderhoud')->assertSee('1 site offline');
});

it('telt sites met updates als aandacht zolang niets offline is', function (): void {
    Site::factory()->create(['updates' => ['core' => [], 'plugins' => [['name' => 'a']], 'themes' => []]]);
    Site::factory()->create(['updates' => ['core' => [], 'plugins' => [], 'themes' => []]]);

    expect(Livewire::test(Sidebar::class)->get('attention'))->toBe(['offline' => 0, 'warnings' => 1]);
});

it('opent de acties van een site vanuit de blokken Lokaal en Live in de sitelijst', function (): void {
    Site::factory()->built()->create(['name' => 'klant']);

    Livewire::test(Dashboard::class)
        ->assertSee('WP-admin lokaal')
        ->assertSee('Openen in editor')
        ->assertSee('Live website openen')
        ->assertSee('WP-admin live')
        ->assertSee('Uptime bekijken')
        ->assertSee('Gegevens bewerken');
});

it('filtert de sitelijst op sites met updates', function (): void {
    Site::factory()->create(['name' => 'met-updates', 'updates' => ['core' => [], 'plugins' => [['name' => 'a']], 'themes' => []]]);
    Site::factory()->create(['name' => 'zonder-updates', 'updates' => ['core' => [], 'plugins' => [], 'themes' => []]]);

    Livewire::test(Dashboard::class)
        ->set('scope', 'updates')
        ->assertSee('met-updates')
        ->assertDontSee('zonder-updates');
});

it('toont een waarschuwing op de sitepagina als de site offline is', function (): void {
    $site = Site::factory()->create(['name' => 'storing', 'uptime_status' => 'down', 'uptime_status_since' => now()->subMinutes(10)]);
    Process::fake(['*' => Process::result(json_encode($site->snapshot))]);

    Livewire::test(Show::class, ['site' => $site])
        ->assertSee('storing is offline')
        ->assertSee('Website proberen');
});

it('verbergt de providerfilter zolang alle sites bij dezelfde provider staan', function (): void {
    Site::factory()->count(2)->create();

    Livewire::test(Dashboard::class)->assertDontSee('Alle providers');

    Site::factory()->hostinger()->create();

    Livewire::test(Dashboard::class)->assertSee('Alle providers');
});
