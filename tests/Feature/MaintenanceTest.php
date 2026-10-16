<?php

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Maintenance\Index;
use App\Models\CommandRun;
use App\Models\Site;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\UpdateChecker;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Process::fake(['*' => Process::result('[]')]);
    $this->actingAs(User::factory()->owner()->create());
});

it('toont per live-site status, updates en back-up', function (): void {
    Site::factory()->create([
        'name' => 'klant-a', 'uptime_status' => 'down',
        'updates' => ['core' => [], 'plugins' => [['name' => 'a'], ['name' => 'b']], 'themes' => []],
        'updates_checked_at' => now()->subHour(),
    ]);
    Site::factory()->snapshotState(['last_backup' => '20200101-120000-push', 'backups' => 1])->create(['name' => 'klant-b']);
    Site::factory()->localOnly()->create(['name' => 'alleen-lokaal']);

    $this->get(route('maintenance.index'))
        ->assertOk()
        ->assertSee('klant-a')
        ->assertSee('klant-b')
        ->assertSee('Offline')
        ->assertSee('2 updates')
        ->assertSee('Geen back-up');

    // de snelzoeker in de layout kent ook lokale sites, dus alleen het onderhoudsscherm zelf controleren
    $component = Livewire::test(Index::class)->assertDontSee('alleen-lokaal');
    expect($component->get('summary'))->toBe(['offline' => 1, 'updates' => 2, 'backups' => 2]);
});

it('leest het tijdstip van een back-up uit de mapnaam', function (): void {
    $site = Site::factory()->snapshotState(['last_backup' => '20261008-160310-push'])->create();

    expect($site->lastBackupAt()->toDateTimeString())->toBe('2026-10-08 16:03:10')
        ->and(Site::factory()->create()->lastBackupAt())->toBeNull();
});

it('zet de updatecontrole van alle sites in de wachtrij op de snelle wachtrij', function (): void {
    Site::factory()->count(2)->create();
    Site::factory()->localOnly()->create();
    Site::factory()->laravel()->create();
    Site::factory()->laravel()->built()->create();

    Livewire::test(Index::class)->call('checkAll');

    // 2 WordPress-sites en 1 gebouwde Laravel-site; lokaal en niet-gebouwd vallen af
    Queue::assertPushed(RunWpOpenCommand::class, 3);
    Queue::assertPushedOn('quick', RunWpOpenCommand::class);
    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => ! $job->notify && $job->run->action === WpOpenAction::Updates);
});

it('zet geen tweede controle klaar voor een site waarvan de controle al loopt', function (): void {
    $site = Site::factory()->create();
    CommandRun::factory()->create(['site_id' => $site->id, 'site_name' => $site->name, 'action' => WpOpenAction::Updates, 'status' => RunStatus::Running]);

    expect(app(UpdateChecker::class)->queue(collect([$site])))->toBe(0);
    Queue::assertNothingPushed();
});

it('controleert op de achtergrond alleen sites met een verouderde controle', function (): void {
    $stale = Site::factory()->create(['updates_checked_at' => now()->subDays(2)]);
    $never = Site::factory()->create();
    Site::factory()->create(['updates_checked_at' => now()->subHours(3)]);

    Artisan::call('updates:check');

    Queue::assertPushed(RunWpOpenCommand::class, 2);
    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->site_id === $stale->id);
    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->site_id === $never->id);
});

it('slaat de dagelijkse controle over als die uit staat, behalve met --force', function (): void {
    Site::factory()->create();
    app(AppSettings::class)->update(['daily_update_check' => false]);

    Artisan::call('updates:check');
    Queue::assertNothingPushed();

    Artisan::call('updates:check', ['--force' => true]);
    Queue::assertPushed(RunWpOpenCommand::class, 1);
});

it('meldt nieuwe updates alleen bij een controle op de achtergrond', function (bool $background, int $expected): void {
    Queue::fake([]);
    $site = Site::factory()->create(['updates' => ['core' => [], 'plugins' => [], 'themes' => []]]);
    $updates = json_encode(['core' => [], 'plugins' => [['name' => 'seo', 'version' => '1', 'update_version' => '2']], 'themes' => []]);
    Process::fake(['*' => Process::result($updates)]);

    $notifier = Mockery::mock(DesktopNotifier::class)->shouldIgnoreMissing();
    $notifier->shouldReceive('updatesFound')->times($expected);
    $this->app->instance(DesktopNotifier::class, $notifier);

    $run = CommandRun::factory()->create(['site_id' => $site->id, 'site_name' => $site->name, 'action' => WpOpenAction::Updates, 'arguments' => ['updates', $site->name, '--json'], 'status' => RunStatus::Queued]);
    Queue::fake();
    (new RunWpOpenCommand($run, notify: ! $background))->handle(app(WpOpen::class), app(SiteRegistry::class), $notifier);

    expect($site->fresh()->updatesCount())->toBe(1);
})->with([
    'op de achtergrond' => [true, 1],
    'handmatig' => [false, 0],
]);
