<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding;
use App\Livewire\SiteListTransfer;
use App\Models\Site;
use App\Models\User;
use App\Services\FilePicker;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    // nooit het echte wpopen (en dus de echte sitelijst) raken als een patroon niet past
    Process::preventStrayProcesses();
    Queue::fake();
    $this->actingAs(User::factory()->owner()->create());
    $this->directory = sys_get_temp_dir().'/sitelijst-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

/**
 * @param  list<array<string, mixed>>  $sites
 */
function siteListJson(array $sites): string
{
    $defaults = ['target' => 'wesley@klant.test', 'ssh_options' => '', 'remote' => '/home/wesley/public_html/wp-content', 'mode' => 'ssh', 'provider' => 'other', 'live_url' => null, 'type' => 'wordpress', 'public_dir' => null];

    return json_encode(['format' => 'bd-deck-sites', 'version' => 1, 'exported_at' => '2026-10-16T12:00:00Z', 'sites' => array_map(fn (array $site): array => [...$defaults, ...$site], $sites)]);
}

describe('exporteren', function (): void {
    beforeEach(function (): void {
        Site::factory()->create(['name' => 'klant', 'notes' => 'Klant wil geen automatische updates.']);
        Site::factory()->create(['name' => 'shop']);
        Site::factory()->localOnly()->create(['name' => 'lokaal', 'notes' => 'Alleen bij mij.']);

        Process::fake(['*export-sites*' => Process::result("· 1 lokale site(s) zonder server niet meegenomen\n".siteListJson([['name' => 'klant'], ['name' => 'shop']])."\n")]);
        $this->path = $this->directory.'/bd-deck-sites.json';
    });

    it('slaat de sitelijst op zonder notities', function (): void {
        $this->mock(FilePicker::class)->shouldReceive('saveJson')
            ->withArgs(fn (string $title, string $default): bool => str_ends_with($default, '/bd-deck-sites-'.now()->format('Y-m-d').'.json'))
            ->andReturn($this->path);

        Livewire::test(SiteListTransfer::class)
            ->call('openExport')
            ->assertDispatched('open-modal', name: 'export-sites')
            ->assertSee(['2 sites gaan mee', 'Eén lokale site zonder server blijft hier', 'Wat gaat er mee?', 'wachtwoorden en de kluis'])
            ->call('export')
            ->assertDispatched('close-modal', name: 'export-sites')
            ->assertDispatched('toast', title: 'Sitelijst opgeslagen (2 sites)');

        $export = json_decode(File::get($this->path), true);

        expect(array_column($export['sites'], 'name'))->toBe(['klant', 'shop'])
            ->and(File::get($this->path))->not->toContain('notes');
        Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 3) === ['export-sites']);
    });

    it('neemt notities alleen mee als dat aangezet is, met een waarschuwing', function (): void {
        $this->mock(FilePicker::class)->shouldReceive('saveJson')->andReturn($this->path);

        Livewire::test(SiteListTransfer::class)
            ->set('includeNotes', true)
            ->assertSee('Notities kunnen gevoelige informatie bevatten')
            ->call('export');

        $export = json_decode(File::get($this->path), true);

        expect($export['sites'][0]['notes'])->toBe('Klant wil geen automatische updates.')
            ->and($export['sites'][1])->not->toHaveKey('notes')
            ->and(File::get($this->path))->not->toContain('Alleen bij mij');
    });

    it('doet niets als de gebruiker het opslaan annuleert', function (): void {
        $this->mock(FilePicker::class)->shouldReceive('saveJson')->andReturnNull();

        Livewire::test(SiteListTransfer::class)->call('export')->assertNotDispatched('toast');

        expect($this->path)->not->toBeFile();
        Process::assertNothingRan();
    });
});

describe('importeren', function (): void {
    beforeEach(function (): void {
        $this->same = Site::factory()->create(['name' => 'zelfde']);
        $this->different = Site::factory()->create(['name' => 'anders', 'notes' => 'Mijn eigen notitie.']);
        $this->emptyNotes = Site::factory()->create(['name' => 'leeg']);
        Site::factory()->localOnly()->create(['name' => 'lokaal']);

        $this->file = $this->directory.'/van-collega.json';
        File::put($this->file, siteListJson([
            ['name' => 'nieuw', 'target' => 'u1@nieuw.test', 'notes' => 'Notitie van de collega.'],
            ['name' => 'zelfde', 'target' => $this->same->data('target'), 'remote' => $this->same->data('remote'), 'provider' => 'wpmudev'],
            ['name' => 'anders', 'target' => 'ander@anders.test', 'notes' => 'Notitie van de collega.'],
            ['name' => 'leeg', 'target' => 'ander@leeg.test', 'notes' => 'Notitie voor leeg.'],
            ['name' => 'lokaal'],
            ['name' => 'kapot', 'ssh_options' => '-i /home/collega/.ssh/id_ed25519'],
            ['name' => 'nieuw', 'target' => 'dubbel@nieuw.test'],
        ]));
        $this->mock(FilePicker::class)->shouldReceive('json')->andReturn($this->file);
    });

    it('toont per site wat er gebeurt en vinkt alleen nieuwe sites aan', function (): void {
        $component = Livewire::test(SiteListTransfer::class)
            ->call('chooseImportFile')
            ->assertDispatched('open-modal', name: 'import-sites')
            ->assertSet('importSelected', ['nieuw'])
            ->assertSee(['Nieuw', 'Staat er al, zelfde gegevens', 'Staat er al, andere gegevens', 'bijwerken', 'Ongeldig', 'persoonlijk pad', 'staat dubbel in het bestand', 'Hier is het een lokale site zonder server', 'Eén site importeren']);

        expect(collect($component->get('importRows'))->pluck('status', 'name')->all())->toBe([
            'nieuw' => 'invalid', 'zelfde' => 'same', 'anders' => 'different', 'leeg' => 'different', 'lokaal' => 'blocked', 'kapot' => 'invalid',
        ])
            ->and($component->get('importRows')[0]['status'])->toBe('new');
    });

    it('importeert de gekozen sites, neemt notities alleen over waar er nog geen is en test de nieuwe verbindingen', function (): void {
        // wat `wpopen list --json` na het importeren teruggeeft: de bestaande sites plus de nieuwe
        $listed = Site::query()->get()->map(fn (Site $site): array => [...$site->snapshot, 'name' => $site->name])
            ->push([...$this->same->snapshot, 'name' => 'nieuw', 'target' => 'u1@nieuw.test']);
        Process::fake([
            '*import-sites*' => Process::result('{"added": ["nieuw"], "updated": ["anders", "leeg"], "skipped": [], "invalid": []}'),
            '*list*--json*' => Process::result($listed->toJson()),
        ]);

        Livewire::test(SiteListTransfer::class)
            ->call('chooseImportFile')
            ->set('importSelected', ['nieuw', 'anders', 'leeg', 'zelfde', 'kapot', 'verzonnen'])
            ->assertSee('3 sites importeren')
            ->call('import')
            ->assertSet('importResult.added', ['nieuw'])
            ->assertDispatched('sites-changed')
            ->assertSee(['Eén site toegevoegd', '2 sites bijgewerkt', 'Wat nu?', 'Verbinding mislukt', 'SSH-sleutels']);

        Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 3) === ['import-sites', $this->file, '--only', 'nieuw', '--only', 'anders', '--only', 'leeg', '--update', '--json']);

        expect(Site::query()->where('name', 'nieuw')->value('notes'))->toBe('Notitie van de collega.')
            ->and($this->different->fresh()->notes)->toBe('Mijn eigen notitie.')
            ->and($this->emptyNotes->fresh()->notes)->toBe('Notitie voor leeg.');

        Queue::assertPushed(RunWpOpenCommand::class, 1);
        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['test', 'nieuw', '--json'] && $job->notify === false);
    });

    it('importeert zonder --update als er alleen nieuwe sites gekozen zijn', function (): void {
        File::put($this->file, siteListJson([['name' => 'nieuw', 'target' => 'u1@nieuw.test']]));
        Process::fake([
            '*import-sites*' => Process::result('{"added": [], "updated": [], "skipped": ["nieuw"], "invalid": []}'),
            '*list*--json*' => Process::result('[]'),
        ]);

        Livewire::test(SiteListTransfer::class)
            ->call('chooseImportFile')
            ->call('import')
            ->assertDontSee('Wat nu?');

        Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 3) === ['import-sites', $this->file, '--only', 'nieuw', '--json']);
        Queue::assertNothingPushed();
    });

    it('importeert niets zonder gekozen site', function (): void {
        Process::fake();

        Livewire::test(SiteListTransfer::class)
            ->call('chooseImportFile')
            ->set('importSelected', [])
            ->call('import')
            ->assertHasErrors('importSelected');

        Process::assertNothingRan();
    });

    it('weigert een bestand dat geen sitelijst is', function (string $contents, string $message): void {
        File::put($this->file, $contents);

        Livewire::test(SiteListTransfer::class)
            ->call('chooseImportFile')
            ->assertNotDispatched('open-modal')
            ->assertDispatched('toast', title: 'Dit bestand kan niet geïmporteerd worden', message: $message);
    })->with([
        'kapotte JSON' => ['{"format":', 'Dit is geen geldig JSON-bestand.'],
        'ander formaat' => ['{"format": "iets", "version": 1, "sites": []}', 'Dit is geen sitelijst van BD Deck.'],
        'nieuwere versie' => ['{"format": "bd-deck-sites", "version": 2, "sites": []}', 'Deze sitelijst komt uit een nieuwere versie van BD Deck; werk BD Deck eerst bij.'],
    ]);
});

it('biedt importeren aan op het dashboard en bij Aan de slag', function (): void {
    Process::fake(['*' => Process::result('[]')]);

    Livewire::test(Dashboard::class)->assertSee(['Sitelijst van een collega importeren', 'start-site-import'])->assertSeeLivewire(SiteListTransfer::class);
    Site::factory()->create();
    Livewire::test(Dashboard::class)->assertSee(['Sitelijst exporteren…', 'Sitelijst importeren…', 'open-site-export']);
    Livewire::test(Onboarding::class)->assertSee('Sitelijst van een collega importeren')->assertSeeLivewire(SiteListTransfer::class);
});
