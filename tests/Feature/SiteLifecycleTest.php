<?php

use App\Enums\WpOpenAction;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding;
use App\Livewire\Sites\Console;
use App\Livewire\Sites\Create;
use App\Livewire\Sites\DeleteDialog;
use App\Livewire\Sites\Migrate;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Process::fake();
    $this->actingAs(User::factory()->owner()->create());
});

describe('verbinding plakken', function (): void {
    it('neemt gebruiker, server en poort over, ook in het serverveld', function (string $field, string $value): void {
        Livewire::test(Create::class)
            ->set($field, $value)
            ->assertSet('form.user', 'u796343828')
            ->assertSet('form.host', '147.93.54.246')
            ->assertSet('form.port', 65002)
            ->assertSet('form.provider', 'hostinger')
            ->assertSet('form.name', '');
    })->with([
        'plakveld' => ['connection', 'ssh -p 65002 u796343828@147.93.54.246'],
        'serverveld' => ['form.host', 'ssh -p 65002 u796343828@147.93.54.246'],
        'poort achteraan' => ['connection', 'ssh u796343828@147.93.54.246 -p 65002'],
        'sftp-adres' => ['connection', 'sftp://u796343828@147.93.54.246:65002'],
    ]);
});

describe('lokale sites', function (): void {
    it('maakt een nieuwe lokale site', function (): void {
        Livewire::test(Create::class)
            ->set('kind', 'lokaal')
            ->set('localName', 'bakkerij')
            ->set('localTitle', 'Bakkerij De Korenaar')
            ->call('createLocal')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->action === WpOpenAction::NewSite
            && $job->run->arguments === ['new', 'bakkerij', '--title', 'Bakkerij De Korenaar']);
    });

    it('gaat na het toevoegen terug naar de sitelijst', function (): void {
        Livewire::test(Create::class)
            ->set('connection', 'ssh -p 65002 u123@1.2.3.4')
            ->set('form.name', 'klant')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->action === WpOpenAction::AddSite);
    });

    it('toont op de sitelijst welke site nog wordt toegevoegd', function (): void {
        Livewire::test(Create::class)->set('connection', 'ssh -p 65002 u123@1.2.3.4')->set('form.name', 'klant')->call('save');

        Livewire::test(Dashboard::class)->assertSee('Site toevoegen')->assertSeeHtml('wire:poll.2s="pollSiteChanges"');
    });

    it('weigert een naam die al bestaat', function (): void {
        Site::factory()->create(['name' => 'bakkerij']);

        Livewire::test(Create::class)->set('localName', 'bakkerij')->call('createLocal')->assertHasErrors('localName');

        Queue::assertNothingPushed();
    });

    it('toont een lokale site in de lijst met een knop om live te zetten', function (): void {
        Site::factory()->localOnly()->create(['name' => 'bakkerij']);

        Livewire::test(Dashboard::class)
            ->assertSee('bakkerij')
            ->assertSee('Alleen lokaal')
            ->assertSee('Nog niet live')
            ->assertSee(route('sites.migrate', 'bakkerij'));
    });
});

describe('verwijderen', function (): void {
    it('opent eerst een bevestiging en verwijdert nog niets', function (): void {
        $site = Site::factory()->create(['name' => 'klant']);

        Livewire::test(DeleteDialog::class)
            ->dispatch('confirm-delete-site', siteId: $site->id)
            ->assertDispatched('open-modal', name: 'delete-site')
            ->assertSee('klant verwijderen');

        Queue::assertNothingPushed();
    });

    it('haalt een gekoppelde site weg zonder de lokale kopie', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(DeleteDialog::class)
            ->call('ask', $site->id)
            ->call('delete')
            ->assertRedirect(route('dashboard'));

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['remove', 'klant', '--yes']);
        expect(Site::query()->where('name', 'klant')->exists())->toBeFalse();
    });

    it('gooit een lokale site ook lokaal weg, want er is geen live-kopie', function (): void {
        $site = Site::factory()->localOnly()->create(['name' => 'bakkerij']);

        Livewire::test(DeleteDialog::class)->call('ask', $site->id)->call('delete');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['remove', 'bakkerij', '--yes', '--purge']);
    });
});

describe('verhuizen', function (): void {
    it('zet een lokale site live op een nieuwe server', function (): void {
        $site = Site::factory()->localOnly()->create(['name' => 'bakkerij']);

        Livewire::test(Migrate::class, ['site' => $site])
            ->set('connection', 'ssh -p 65002 u123@1.2.3.4')
            ->set('form.liveUrl', 'https://bakkerij.nl')
            ->set('form.password', 'ssh-geheim')
            ->set('confirmText', 'bakkerij')
            ->call('migrate')
            ->assertHasNoErrors();

        Queue::assertPushedOn('default', RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->action === WpOpenAction::Migrate
            && $job->run->arguments === ['migrate', 'bakkerij', '-p', '65002', 'u123@1.2.3.4', '--url', 'https://bakkerij.nl', '--yes', '--provider', 'hostinger']
            && $job->secretEnvironment === ['WPO_PASS' => 'ssh-geheim']);
    });

    it('bewaart bij een verhuizing de oude server en haalt eerst de uploads op', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(Migrate::class, ['site' => $site])
            ->set('form.user', 'jij')
            ->set('form.host', 'klant.tempurl.host')
            ->set('form.provider', 'wpmudev')
            ->set('form.liveUrl', 'https://klant.tempurl.host')
            ->set('confirmText', 'klant')
            ->call('migrate')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['migrate', 'klant', 'jij@klant.tempurl.host', '--url', 'https://klant.tempurl.host', '--yes', '--provider', 'wpmudev', '--keep-old']);
    });

    it('vraagt het nieuwe adres en de sitenaam als bevestiging', function (): void {
        $site = Site::factory()->localOnly()->create(['name' => 'bakkerij']);

        Livewire::test(Migrate::class, ['site' => $site])
            ->set('form.user', 'u123')
            ->set('form.host', 'server.example')
            ->call('migrate')
            ->assertHasErrors('form.liveUrl')
            ->set('form.liveUrl', 'https://bakkerij.nl')
            ->call('migrate')
            ->assertHasErrors('confirmText');

        Queue::assertNothingPushed();
    });

    it('zet alleen het domein om', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(Migrate::class, ['site' => $site])
            ->set('newDomain', 'https://klant.nl')
            ->call('changeDomain')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['domain', 'klant', 'https://klant.nl', '--yes']);
    });
});

it('helpt een nieuwe gebruiker op weg', function (): void {
    Livewire::test(Onboarding::class)
        ->assertSee('Aan de slag')
        ->assertSee('Sleutel bij je hosting zetten')
        ->assertSee('Hostinger');
});

describe('Laravel', function (): void {
    it('toont een Laravel-site zonder WordPress-knoppen', function (): void {
        Site::factory()->laravel()->built()->create(['name' => 'shop']);

        Livewire::test(Dashboard::class)
            ->assertSee('shop')
            ->assertSee('Laravel')
            ->assertDontSee('WP-admin lokaal')
            ->assertDontSee(route('sites.migrate', 'shop'));
    });

    it('voert artisan uit op live via de console', function (): void {
        $site = Site::factory()->laravel()->built()->create(['name' => 'shop']);

        Livewire::test(Console::class, ['site' => $site])
            ->assertSee('php artisan')
            ->set('live', true)
            ->set('command', 'php artisan migrate:status')
            ->call('execute')
            ->assertHasNoErrors();

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->action === WpOpenAction::Artisan
            && $job->run->arguments === ['artisan', 'shop', '--live', '--', 'migrate:status']);
    });

    it('stuurt verhuizen terug naar de sitepagina', function (): void {
        $site = Site::factory()->laravel()->built()->create(['name' => 'shop']);

        Livewire::test(Migrate::class, ['site' => $site])->assertRedirect(route('sites.show', 'shop'));
    });
});

describe('installatiescript', function (): void {
    beforeEach(function (): void {
        $this->home = sys_get_temp_dir().'/bd-deck-home-'.bin2hex(random_bytes(4));
        mkdir($this->home);
        $this->previousHome = getenv('HOME');
        putenv('HOME='.$this->home);
    });

    afterEach(function (): void {
        putenv('HOME='.$this->previousHome);
        File::deleteDirectory($this->home);
    });

    it('toont collega\'s de installatieregel van GitHub', function (): void {
        Livewire::test(Onboarding::class)
            ->assertSee('curl -fsSL https://raw.githubusercontent.com/ICTtrying/bd-deck/main/bin/install-mint | bash');
    });

    it('opent een terminal die het script draait', function (): void {
        Livewire::test(Onboarding::class)->call('runInstaller')->assertDispatched('toast');

        Process::assertRan(fn ($process): bool => in_array('setsid', (array) $process->command, true)
            && str_contains(implode(' ', (array) $process->command), 'bin/install-mint'));
    });
});
