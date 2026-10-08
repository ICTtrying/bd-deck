<?php

use App\Livewire\Settings\PremiumFiles;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\FilePicker;
use App\Services\Vault;
use App\Services\WpOpen\PremiumLibrary;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->owner('oud-wachtwoord-123')->create();
    app(Vault::class)->unlock($user, 'oud-wachtwoord-123');
    $this->actingAs($user);

    $this->home = sys_get_temp_dir().'/bd-deck-premium-'.uniqid();
    File::ensureDirectoryExists($this->home);
    putenv('HOME='.$this->home);
    $this->premium = app(AppSettings::class)->premiumDirectory();

    $this->makeZip = function (string $path, array $files, ?int $mtime = null): string {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $mtime === null || touch($path, $mtime);

        return $path;
    };
});

afterEach(function (): void {
    File::deleteDirectory($this->home);
});

it('voegt gekozen zips toe en herkent thema en plugin, ook in een ThemeForest-pakket', function (): void {
    $theme = ($this->makeZip)($this->home.'/enfold-inner.zip', ['enfold/style.css' => '/* Enfold */']);
    $package = ($this->makeZip)($this->home.'/Enfold Package.zip', ['docs/readme.txt' => 'x', 'enfold.zip' => file_get_contents($theme)]);
    $plugin = ($this->makeZip)($this->home.'/smush.zip', ['wp-smush-pro/smush.php' => '<?php']);
    $nonsense = ($this->makeZip)($this->home.'/foto.zip', ['foto.jpg' => 'x']);

    $this->mock(FilePicker::class)->shouldReceive('zips')->andReturn([$package, $plugin, $nonsense]);

    Livewire::test(PremiumFiles::class)
        ->call('choose')
        ->assertSee('enfold')
        ->assertSee('wp-smush-pro')
        ->assertSee('Thema')
        ->assertDispatched('toast', title: 'Overgeslagen: geen thema of plugin');

    expect($this->premium.'/Enfold Package.zip')->toBeFile()
        ->and($this->premium.'/smush.zip')->toBeFile()
        ->and($this->premium.'/foto.zip')->not->toBeFile();
});

it('gebruikt per plugin alleen de nieuwste zip en toont de oudere als oudere versie', function (): void {
    File::ensureDirectoryExists($this->premium);
    ($this->makeZip)($this->premium.'/dash-4.0.zip', ['wpmudev-updates/plugin.php' => '<?php'], 1000);
    ($this->makeZip)($this->premium.'/dash-4.1.zip', ['wpmudev-updates/plugin.php' => '<?php'], 2000);

    $items = app(PremiumLibrary::class)->items();

    expect($items->firstWhere('file', 'dash-4.1.zip')['active'])->toBeTrue()
        ->and($items->firstWhere('file', 'dash-4.0.zip')['active'])->toBeFalse();

    Livewire::test(PremiumFiles::class)->assertSee('Oudere versie');
});

it('verwijdert een zip, maar nooit een pad buiten de map', function (): void {
    File::ensureDirectoryExists($this->premium);
    ($this->makeZip)($this->premium.'/smush.zip', ['wp-smush-pro/smush.php' => '<?php']);

    Livewire::test(PremiumFiles::class)->call('remove', 'smush.zip');
    expect($this->premium.'/smush.zip')->not->toBeFile();

    expect(fn () => Livewire::test(PremiumFiles::class)->call('remove', '../.bashrc'))->toThrow(InvalidArgumentException::class);
});
