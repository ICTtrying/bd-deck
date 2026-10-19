<?php

use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\SafeUpdatePanel;
use App\Livewire\Sites\Show;
use App\Models\CommandRun;
use App\Models\Site;
use App\Models\User;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Process::fake();
    $this->actingAs(User::factory()->owner()->create());

    $this->updates = [
        'core' => [['version' => '6.9']],
        'plugins' => [
            ['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true],
            ['name' => 'wordfence', 'title' => 'Wordfence', 'version' => '8.0', 'update_version' => '8.1', 'active' => true],
        ],
        'themes' => [['name' => 'astra', 'title' => 'Astra', 'version' => '4.1', 'update_version' => '4.2']],
    ];
});

describe('commando', function (): void {
    it('bouwt een veilige update met alleen de gekozen onderdelen', function (): void {
        $site = Site::factory()->create(['name' => 'klant']);

        $command = WpOpenCommand::safeUpdate($site, ['plugin:akismet', 'core'], live: false, force: true);

        expect($command->action)->toBe(WpOpenAction::SafeUpdate)
            ->and($command->arguments)->toBe(['safe-update', 'klant', '--only', 'plugin:akismet', '--only', 'core', '--no-live', '--force', '--yes'])
            ->and(WpOpenCommand::safeUpdate($site)->arguments)->toBe(['safe-update', 'klant', '--yes']);
    });

    it('weigert Laravel, lokale sites en ongeldige onderdelen', function (Closure $site, array $only, string $message): void {
        expect(fn (): WpOpenCommand => WpOpenCommand::safeUpdate($site(), $only))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'Laravel' => [fn (): Site => Site::factory()->laravel()->create(), [], 'alleen bij WordPress-sites'],
        'lokaal' => [fn (): Site => Site::factory()->localOnly()->create(), [], 'nog geen live-server'],
        'ongeldig' => [fn (): Site => Site::factory()->create(), ['plugin:../x'], 'Ongeldige update'],
        'onbekend soort' => [fn (): Site => Site::factory()->create(), ['mu-plugin:x'], 'Ongeldige update'],
    ]);
});

describe('paneel', function (): void {
    it('opent met alle updates aangevinkt en zet ze zonder --only live', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant', 'updates' => $this->updates, 'updates_checked_at' => now()]);

        Livewire::test(SafeUpdatePanel::class, ['site' => $site])
            ->dispatch('open-safe-update')
            ->assertDispatched('open-modal', name: 'safe-update')
            ->assertSet('selected', ['core', 'plugin:akismet', 'plugin:wordfence', 'theme:astra'])
            ->assertSee(['Akismet', '5.3', '5.4', 'Ophalen van live', 'Live zetten', 'Bijwerken en live zetten', 'blijft live ongemoeid'])
            ->assertDontSee('opnieuw gekeken')
            ->call('start')
            ->assertDispatched('close-modal', name: 'safe-update');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['safe-update', 'klant', '--yes']);
    });

    it('stuurt alleen de gekozen updates mee en kan alleen lokaal testen', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant', 'updates' => $this->updates, 'updates_checked_at' => now()->subHours(3)]);

        Livewire::test(SafeUpdatePanel::class, ['site' => $site])
            ->call('open')
            ->assertSee('opnieuw gekeken')
            ->set('selected', ['plugin:akismet', 'theme:astra'])
            ->set('live', false)
            ->assertSee('Alleen lokaal testen')
            ->call('start');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['safe-update', 'klant', '--only', 'plugin:akismet', '--only', 'theme:astra', '--no-live', '--yes']);
    });

    it('start niets zonder keuze', function (): void {
        $site = Site::factory()->built()->create(['updates' => $this->updates]);

        Livewire::test(SafeUpdatePanel::class, ['site' => $site])
            ->call('open')
            ->set('selected', [])
            ->call('start')
            ->assertHasErrors('selected');

        Queue::assertNothingPushed();
    });

    it('laat WordPress zelf buiten de keuze bij een site met alleen SFTP', function (): void {
        $site = Site::factory()->built()->sftp()->create(['updates' => $this->updates]);

        Livewire::test(SafeUpdatePanel::class, ['site' => $site])
            ->call('open')
            ->assertSet('selected', ['plugin:akismet', 'plugin:wordfence', 'theme:astra'])
            ->assertSee('alleen SFTP');
    });

    it('herhaalt een mislukte run met dezelfde keuze, zonder de boosdoener', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant', 'updates' => $this->updates]);
        CommandRun::factory()->failed("WPO_CULPRIT=plugin:akismet\n✗ Mis\n")->create([
            'site_id' => $site->id, 'action' => WpOpenAction::SafeUpdate,
            'arguments' => ['safe-update', 'klant', '--only', 'plugin:akismet', '--only', 'theme:astra', '--yes'],
        ]);

        Livewire::test(SafeUpdatePanel::class, ['site' => $site])
            ->dispatch('open-safe-update', without: 'plugin:akismet')
            ->assertSet('selected', ['theme:astra']);
    });
});

describe('sitepagina', function (): void {
    it('biedt veilig bijwerken aan bij updates op live', function (): void {
        $site = Site::factory()->built()->create(['updates' => $this->updates, 'updates_checked_at' => now()]);

        Livewire::test(Show::class, ['site' => $site])
            ->assertSee('Veilig bijwerken')
            ->assertSeeLivewire(SafeUpdatePanel::class);
    });

    it('vraagt eerst om lokaal bouwen en verbergt de knop bij lokale sites', function (): void {
        Livewire::test(Show::class, ['site' => Site::factory()->create(['updates' => $this->updates])])
            ->assertSee('Bouw de site eerst lokaal; de updates worden lokaal getest');

        Livewire::test(Show::class, ['site' => Site::factory()->localOnly()->create(['updates' => $this->updates])])
            ->assertDontSee('Veilig bijwerken');
    });

    it('biedt na een mislukte update aan het zonder de boosdoener opnieuw te proberen', function (): void {
        $site = Site::factory()->built()->create(['updates' => $this->updates]);
        $run = CommandRun::factory()->failed("WPO_CULPRIT=plugin:akismet\n✗ Na het bijwerken van plugin akismet gaat het mis.\n")->create([
            'site_id' => $site->id, 'action' => WpOpenAction::SafeUpdate, 'label' => WpOpenAction::SafeUpdate->label(),
        ]);

        expect($run->culprit())->toBe('plugin:akismet');

        Livewire::test(Show::class, ['site' => $site])->assertSee('Opnieuw zonder akismet');
    });
});

it('leest alleen bij een veilige update de boosdoener uit het log', function (): void {
    expect(CommandRun::factory()->make(['action' => WpOpenAction::SafeUpdate, 'output' => "WPO_CULPRIT=core\n"])->culprit())->toBe('core')
        ->and(CommandRun::factory()->make(['action' => WpOpenAction::SafeUpdate, 'output' => "✗ Mis\n"])->culprit())->toBeNull()
        ->and(CommandRun::factory()->make(['action' => WpOpenAction::Push, 'output' => "WPO_CULPRIT=core\n"])->culprit())->toBeNull();
});
