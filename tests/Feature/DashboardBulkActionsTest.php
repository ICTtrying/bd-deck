<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Dashboard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Process::fake(['*' => Process::result('[]')]);
    Queue::fake();
    $this->actingAs(User::factory()->owner()->create());

    $this->built = Site::factory()->built()->create(['name' => 'gebouwd']);
    $this->remoteOnly = Site::factory()->create(['name' => 'alleen-live']);
    $this->local = Site::factory()->localOnly()->create(['name' => 'lokaal']);
});

/**
 * @return list<list<string>>
 */
function queuedArguments(): array
{
    return Queue::pushed(RunWpOpenCommand::class)->map(fn (RunWpOpenCommand $job): array => $job->run->arguments)->values()->all();
}

it('controleert updates voor elke geschikte site en slaat de rest over', function (): void {
    $laravel = Site::factory()->laravel()->create(['name' => 'shop']);
    $unbuiltLocal = Site::factory()->localOnly()->snapshotState(['built' => false])->create(['name' => 'kaal']);

    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->remoteOnly->id, $this->local->id, $laravel->id, $unbuiltLocal->id])
        ->call('bulkUpdates')
        ->assertSet('selected', [])
        ->assertDispatched('toast', title: 'Updates controleren', message: 'Gestart voor 3 sites · 2 overgeslagen (nog niet gebouwd)');

    expect(queuedArguments())->toEqualCanonicalizing([
        ['updates', 'gebouwd', '--json'],
        ['updates', 'alleen-live', '--json'],
        ['updates', 'lokaal', '--json'],
    ]);
});

it('legt de lokale cache alleen bij gebouwde sites', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->remoteOnly->id, $this->local->id])
        ->call('bulkCache', 'local')
        ->assertSet('selected', [])
        ->assertDispatched('toast', title: 'Lokale cache legen', message: 'Gestart voor 2 sites · 1 overgeslagen (nog niet gebouwd)');

    expect(queuedArguments())->toEqualCanonicalizing([['cache', 'gebouwd'], ['cache', 'lokaal']]);
});

it('vraagt bevestiging voordat de live cache van meerdere sites geleegd wordt', function (): void {
    $component = Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->remoteOnly->id, $this->local->id])
        ->call('bulkCache', 'live')
        ->assertDispatched('open-modal', name: 'bulk-live-cache')
        ->assertSee('Cache legen op 2 sites');

    Queue::assertNothingPushed();

    $component->call('confirmLiveCache')
        ->assertSet('selected', [])
        ->assertDispatched('toast', title: 'Live cache legen', message: 'Gestart voor 2 sites');

    expect(queuedArguments())->toEqualCanonicalizing([['cache', 'gebouwd', '--live'], ['cache', 'alleen-live', '--live']]);
});

it('legt de live cache van één site meteen en noemt sites zonder server', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->local->id])
        ->call('bulkCache', 'live')
        ->assertNotDispatched('open-modal')
        ->assertDispatched('toast', title: 'Live cache legen', message: 'Gestart voor één site · 1 overgeslagen (geen live-server)');

    expect(queuedArguments())->toBe([['cache', 'gebouwd', '--live']]);
});

it('maakt per site een back-up van de onderdelen die erbij passen', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->remoteOnly->id, $this->local->id])
        ->set('backupLiveFiles', true)
        ->call('bulkBackup')
        ->assertSet('selected', [])
        ->assertDispatched('toast', title: 'Back-up maken', message: 'Gestart voor 3 sites');

    expect(queuedArguments())->toEqualCanonicalizing([
        ['backup', 'gebouwd', '--local', '--live-db', '--live-files'],
        ['backup', 'alleen-live', '--live-db', '--live-files'],
        ['backup', 'lokaal', '--local'],
    ]);
});

it('slaat sites over waar geen enkel gekozen back-up-onderdeel past', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id, $this->remoteOnly->id])
        ->set('backupLiveDatabase', false)
        ->call('bulkBackup')
        ->assertDispatched('toast', title: 'Back-up maken', message: 'Gestart voor één site · 1 overgeslagen (nog niet gebouwd)');

    expect(queuedArguments())->toBe([['backup', 'gebouwd', '--local']]);
});

it('start geen back-up zonder onderdelen', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [$this->built->id])
        ->set('backupLocal', false)
        ->set('backupLiveDatabase', false)
        ->call('bulkBackup')
        ->assertSet('selected', [$this->built->id])
        ->assertDispatched('toast', title: 'Kies minstens één onderdeel voor de back-up.');

    Queue::assertNothingPushed();
});

it('negeert id\'s van sites die niet bestaan', function (): void {
    Livewire::test(Dashboard::class)
        ->set('selected', [999, $this->built->id, 'onzin'])
        ->assertSee('1 geselecteerd')
        ->call('bulkCache', 'local')
        ->assertDispatched('toast', message: 'Gestart voor één site');

    expect(queuedArguments())->toBe([['cache', 'gebouwd']]);
});

it('selecteert alle zichtbare sites en zet ze ook weer uit', function (): void {
    Livewire::test(Dashboard::class)
        ->set('search', 'lokaal')
        ->call('toggleSelectAll')
        ->assertSet('selected', [$this->local->id])
        ->set('search', '')
        ->call('toggleSelectAll')
        ->assertSet('selected', [$this->local->id, $this->remoteOnly->id, $this->built->id])
        ->assertSee('3 geselecteerd')
        ->call('toggleSelectAll')
        ->assertSet('selected', []);
});

it('toont de actiebalk alleen met een selectie en wist die op verzoek', function (): void {
    Livewire::test(Dashboard::class)
        ->assertDontSee('Selectie wissen')
        ->set('selected', [$this->built->id])
        ->assertSee(['1 geselecteerd', 'Updates controleren', 'Cache legen', 'Back-up maken', 'Selectie wissen'])
        ->call('clearSelection')
        ->assertSet('selected', [])
        ->assertDontSee('Selectie wissen');
});
