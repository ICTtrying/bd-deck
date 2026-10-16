<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\Create;
use App\Models\User;
use App\Services\AppSettings;
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

        $this->originalHome = getenv('HOME');
        $this->home = sys_get_temp_dir().'/bd-deck-packages-'.uniqid();
        putenv('HOME='.$this->home);
        $premium = app(AppSettings::class)->premiumDirectory();
        File::ensureDirectoryExists($premium);

        foreach (['enfold.zip' => ['enfold/style.css' => '/* Enfold */'], 'smush.zip' => ['wp-smush-pro/smush.php' => '<?php']] as $file => $contents) {
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

        expect(($this->queued)(['new', 'proef']))->toBeTrue();
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
            'new', 'proef', '--premium', 'theme:enfold',
            '--plugin', 'forminator', '--plugin', 'wordfence', '--plugin', 'redirection',
        ]))->toBeTrue();
    });

    it('installeert geen premium-zips als alles uit staat', function (): void {
        Livewire::test(Create::class)
            ->set('localName', 'proef')
            ->set('premium', [])
            ->call('createLocal');

        expect(($this->queued)(['new', 'proef', '--no-premium']))->toBeTrue();
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
