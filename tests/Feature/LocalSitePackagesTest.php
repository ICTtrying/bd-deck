<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\Create;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\WpOpen\PremiumLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeServer;

describe('plugins kiezen', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Process::fake();
        $this->actingAs(User::factory()->owner()->create());
        Setting::query()->create(['key' => 'local_site_suffix', 'value' => 'abcd']);

        $this->originalHome = getenv('HOME');
        $this->home = sys_get_temp_dir().'/bd-deck-packages-'.uniqid();
        putenv('HOME='.$this->home);
        $premium = app(AppSettings::class)->premiumDirectory();
        File::ensureDirectoryExists($premium);

        foreach (['enfold.zip' => ['enfold/style.css' => "/*\nTheme Name: Enfold\n*/"], 'smush.zip' => ['wp-smush-pro/smush.php' => "<?php\n/**\n * Plugin Name: Smush Pro\n */"]] as $file => $contents) {
            $zip = new ZipArchive;
            $zip->open($premium.'/'.$file, ZipArchive::CREATE);
            foreach ($contents as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
        }

        $this->queued = fn (array $arguments): bool => Queue::pushed(RunWpOpenCommand::class)->contains(fn (RunWpOpenCommand $job): bool => $job->run->arguments === $arguments);
    });

    afterEach(function (): void {
        putenv('HOME='.$this->originalHome);
        File::deleteDirectory($this->home);
    });

    it('vinkt standaard alle premium-zips aan en laat wpopen dan zelf alles pakken', function (): void {
        Livewire::test(Create::class)
            ->assertSet('premium', ['plugin:wp-smush-pro', 'theme:enfold'])
            ->set('localName', 'proef')
            ->call('createLocal')
            ->assertHasNoErrors();

        expect(($this->queued)(['new', 'proef-abcd']))->toBeTrue();
    });

    it('geeft alleen de gekozen pakketten door', function (): void {
        Livewire::test(Create::class)
            ->set('localName', 'proef')
            ->set('premium', ['theme:enfold'])
            ->set('plugins', ['forminator'])
            ->set('extraPlugins', 'Wordfence, redirection forminator')
            ->call('createLocal')
            ->assertHasNoErrors();

        expect(($this->queued)([
            'new', 'proef-abcd', '--premium', 'theme:enfold',
            '--plugin', 'forminator', '--plugin', 'wordfence', '--plugin', 'redirection',
        ]))->toBeTrue();
    });

    it('installeert geen premium-zips als alles uit staat', function (): void {
        Livewire::test(Create::class)
            ->set('localName', 'proef')
            ->set('premium', [])
            ->call('createLocal');

        expect(($this->queued)(['new', 'proef-abcd', '--no-premium']))->toBeTrue();
    });

    it('toont de naam uit de plugin- en themakop', function (): void {
        Livewire::test(Create::class)->set('kind', 'lokaal')->assertSee('Smush Pro');

        expect(app(PremiumLibrary::class)->items()->pluck('name', 'slug')->all())
            ->toMatchArray(['enfold' => 'Enfold', 'wp-smush-pro' => 'Smush Pro']);
    });

    it('onthoudt de keuze voor elke nieuwe site', function (): void {
        Livewire::test(Create::class)
            ->set('premium', ['theme:enfold'])
            ->set('plugins', ['forminator', 'wp-smushit'])
            ->set('extraPlugins', 'wordfence')
            ->call('savePackages')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        Livewire::test(Create::class)
            ->assertSet('premium', ['theme:enfold'])
            ->assertSet('plugins', ['forminator', 'wp-smushit'])
            ->assertSet('extraPlugins', 'wordfence')
            ->set('localName', 'proef')
            ->call('createLocal');

        expect(($this->queued)(['new', 'proef-abcd', '--premium', 'theme:enfold', '--plugin', 'forminator', '--plugin', 'wp-smushit', '--plugin', 'wordfence']))->toBeTrue();
    });

    it('laat opgeslagen zips weg die er niet meer zijn', function (): void {
        app(AppSettings::class)->update(['new_site_packages' => ['premium' => ['theme:enfold', 'plugin:weg'], 'plugins' => ['bestaat-niet'], 'extra_plugins' => '']]);

        Livewire::test(Create::class)
            ->assertSet('premium', ['theme:enfold'])
            ->assertSet('plugins', []);
    });

    it('slaat geen ongeldige keuze op', function (): void {
        Livewire::test(Create::class)
            ->set('extraPlugins', 'word;fence')
            ->call('savePackages')
            ->assertHasErrors('extraPlugins');

        expect(app(AppSettings::class)->newSitePackages())->toBeNull();
    });

    it('weigert onbekende of ongeldige pakketten', function (string $property, mixed $value): void {
        Livewire::test(Create::class)
            ->set('localName', 'proef')
            ->set($property, $value)
            ->call('createLocal')
            ->assertHasErrors();

        Queue::assertNothingPushed();
    })->with([
        'premium die er niet is' => ['premium', ['theme:onbekend']],
        'plugin buiten de lijst' => ['plugins', ['iets-anders']],
        'eigen plugin met rare tekens' => ['extraPlugins', 'word;fence'],
    ]);
});

describe('wpopen new', function (): void {
    beforeEach(function (): void {
        $this->server = FakeServer::create();
    });

    afterEach(function (): void {
        $this->server->destroy();
    });

    it('weigert ongeldige pakketten voordat er iets gemaakt wordt', function (array $arguments, string $message): void {
        $result = $this->server->run(['new', 'proef', ...$arguments]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($message)
            ->and($this->server->sitesFile())->not->toContain('proef');
    })->with([
        'plugin' => [['--plugin', 'Word Fence'], 'Ongeldige plugin'],
        'thema' => [['--theme', 'astra;rm'], 'Ongeldig thema'],
        'premium' => [['--premium', 'enfold'], 'Ongeldige premium-keuze'],
    ]);
});
