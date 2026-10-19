<?php

use Tests\Support\FakeServer;

beforeEach(function (): void {
    $this->server = FakeServer::create();
    $this->server->writeFile('plugins/akismet/akismet.php', "<?php\n/* Plugin Name: Akismet\n * Version: 5.3 */\n");
    $this->server->writeFile('plugins/akismet/oud.php', "<?php // alleen in 5.3\n");
    $this->server->writeFile('themes/demo/style.css', "/* Theme Name: Demo\n * Version: 1.0 */\n");
    $this->wpContent = $this->server->buildLocalSite();

    $this->server->fakeWordPressUpdates([
        'plugins' => [['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true]],
    ]);
    $this->server->addPackage('plugins', 'akismet', [
        'akismet.php' => "<?php\n/* Plugin Name: Akismet\n * Version: 5.4 */\n",
        'nieuw.php' => "<?php // nieuw in 5.4\n",
    ]);

    $this->backup = fn (): string => $this->server->sites.'/.wpopen-backups/demo/'.collect($this->server->backups())->first(fn (string $id): bool => str_ends_with($id, '-safe-update'));
});

afterEach(function (): void {
    $this->server->destroy();
});

it('werkt plugins, thema en WordPress lokaal bij, test ze en zet ze live met back-up', function (): void {
    $this->server->setAvailableUpdates([
        'core' => [['version' => '6.9']],
        'plugins' => [['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true]],
        'themes' => [['name' => 'demo', 'title' => 'Demo', 'version' => '1.0', 'update_version' => '2.0']],
    ]);
    $this->server->addPackage('themes', 'demo', ['style.css' => "/* Theme Name: Demo\n * Version: 2.0 */\n", 'functions.php' => "<?php // telefoon 020\n"]);

    $result = $this->server->run(['safe-update', 'demo', '--yes']);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($result->output())->toContain('Stap 1/6', 'Stap 6/6', 'plugin akismet 5.3 → 5.4', 'thema demo 1.0 → 2.0', 'WordPress 6.8.1 → 6.9', 'Bijgewerkt en live! Back-up: ')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.4')
        ->and($this->server->readFile('plugins/akismet/nieuw.php'))->not->toBeNull()
        ->and($this->server->readFile('plugins/akismet/oud.php'))->toBeNull()
        ->and($this->server->readFile('themes/demo/style.css'))->toContain('2.0')
        ->and(trim((string) file_get_contents($this->server->root.'/server/core-version')))->toBe('6.9')
        ->and($this->server->calls())->toContain('wp db export', 'core update-db');

    // het thema staat in git: vastgelegd zoals bij ophalen van live
    $git = fn (array $arguments): string => trim($this->server->git($this->wpContent, $arguments));
    expect($git(['log', '-1', '--format=%s', 'main']))->toContain('Updates: ', 'thema demo 1.0 → 2.0')
        ->and($git(['rev-parse', 'deployed']))->toBe($git(['rev-parse', 'main']))
        ->and($git(['branch', '--show-current']))->toBe('dev')
        ->and($git(['status', '--porcelain']))->toBe('')
        ->and($git(['merge-base', '--is-ancestor', 'main', 'dev']) === '')->toBeTrue();
});

it('draait een veilige update terug met restore --live-files', function (): void {
    $this->server->run(['safe-update', 'demo', '--yes'])->throw();
    $id = basename(($this->backup)());

    $result = $this->server->run(['restore', 'demo', $id, '--live-files', '--yes']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.3')
        ->and($this->server->readFile('plugins/akismet/oud.php'))->not->toBeNull()
        ->and($this->server->readFile('plugins/akismet/nieuw.php'))->toBeNull();
});

it('zet lokaal alles terug en laat live met rust als een update een pagina breekt', function (): void {
    $this->server->addPackage('plugins', 'akismet', ['akismet.php' => "<?php\n/* Version: 5.4 */\nFATAL\n"]);

    $result = $this->server->run(['safe-update', 'demo', '--yes']);

    expect($result->failed())->toBeTrue()
        ->and($result->output())->toContain('WPO_CULPRIT=plugin:akismet')
        ->and($result->errorOutput())->toContain('plugin akismet', 'live is niet aangeraakt', 'PHP Fatal error')
        ->and($result->output().$result->errorOutput())->not->toContain('Back-up: ')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.3')
        ->and(file_get_contents($this->wpContent.'/plugins/akismet/akismet.php'))->toContain('5.3')
        ->and(is_file($this->wpContent.'/plugins/akismet/nieuw.php'))->toBeFalse()
        ->and($this->server->calls())->toContain('import-db');
});

it('laat fouten die er vóór de update al waren niet meetellen', function (): void {
    $this->server->writeFile('plugins/eigen/eigen.php', "<?php // FATAL al jaren\n");
    file_put_contents($this->wpContent.'/plugins/eigen/eigen.php', "<?php // FATAL al jaren\n");

    $result = $this->server->run(['safe-update', 'demo', '--yes']);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($result->output())->toContain('Werkte vóór de update al niet', 'PHP-fouten die er al waren')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.4');
});

it('weigert live te overschrijven als live tijdens de update is aangepast', function (): void {
    $result = $this->server->run(['safe-update', 'demo', '--yes'], ['FAKE_LIVE_CHANGE' => 'plugins/akismet/akismet.php']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Live is aangepast buiten wpopen om', 'plugins/akismet/akismet.php')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('hotfix')
        ->and($this->server->readFile('plugins/akismet/nieuw.php'))->toBeNull()
        ->and(file_get_contents($this->wpContent.'/plugins/akismet/akismet.php'))->toContain('5.3');
});

it('overschrijft een live-wijziging alleen met --force', function (): void {
    $result = $this->server->run(['safe-update', 'demo', '--yes', '--force'], ['FAKE_LIVE_CHANGE' => 'plugins/akismet/akismet.php']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.4');
});

it('zet live automatisch terug als live na het bijwerken fouten geeft', function (): void {
    $this->server->setAvailableUpdates([
        'core' => [['version' => '6.9']],
        'plugins' => [['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true]],
    ]);
    $this->server->addPackage('plugins', 'akismet', ['akismet.php' => "<?php\n/* Version: 5.4 */\n// KAPOT_OP_LIVE\n"]);

    $result = $this->server->run(['safe-update', 'demo', '--yes']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Live gaf fouten na het bijwerken; alles is teruggezet')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.3')
        ->and($this->server->readFile('plugins/akismet/nieuw.php'))->toBeNull()
        ->and($this->server->readFile('plugins/akismet/oud.php'))->not->toBeNull()
        ->and(trim((string) file_get_contents($this->server->root.'/server/core-version')))->toBe('6.8.1')
        ->and($this->server->imported())->not->toBeNull()
        ->and(file_get_contents($this->wpContent.'/plugins/akismet/akismet.php'))->toContain('5.3');
});

it('test alleen lokaal met --no-live', function (): void {
    $result = $this->server->run(['safe-update', 'demo', '--yes', '--no-live']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Stap 4/4', 'live is niet aangeraakt')
        ->and($result->output())->not->toContain('Back-up: ')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.3')
        ->and(file_get_contents($this->wpContent.'/plugins/akismet/akismet.php'))->toContain('5.4');
});

it('werkt alleen de gekozen updates bij', function (): void {
    $this->server->setAvailableUpdates([
        'plugins' => [['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true]],
        'themes' => [['name' => 'demo', 'title' => 'Demo', 'version' => '1.0', 'update_version' => '2.0']],
    ]);
    $this->server->addPackage('themes', 'demo', ['style.css' => "/* Theme Name: Demo\n * Version: 2.0 */\n"]);

    $result = $this->server->run(['safe-update', 'demo', '--yes', '--only', 'theme:demo']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->server->readFile('themes/demo/style.css'))->toContain('2.0')
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.3');
});

it('slaat WordPress zelf over bij een site met alleen SFTP en zet de plugin via SFTP live', function (): void {
    $this->server->useSftp();
    $this->server->setAvailableUpdates([
        'core' => [['version' => '6.9']],
        'plugins' => [['name' => 'akismet', 'title' => 'Akismet', 'version' => '5.3', 'update_version' => '5.4', 'active' => true]],
    ]);

    $result = $this->server->run(['safe-update', 'demo', '--yes']);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($result->output())->toContain('WordPress zelf overgeslagen', 'Back-up: ')
        ->and($result->output())->not->toContain('WordPress 6.8.1 → 6.9')
        ->and(is_file($this->server->root.'/server/core-version'))->toBeFalse()
        ->and($this->server->readFile('plugins/akismet/akismet.php'))->toContain('5.4')
        ->and($this->server->readFile('plugins/akismet/oud.php'))->toBeNull()
        ->and($this->server->calls())->toContain('lftp put')
        ->and($this->server->calls())->not->toContain('ssh ');
});

it('weigert ongeldige keuzes, Laravel en openstaand werk', function (array $arguments, string $message, ?string $dirtyFile = null): void {
    if ($dirtyFile !== null) {
        file_put_contents($this->wpContent.'/'.$dirtyFile, "<?php // half af\n");
    }

    $result = $this->server->run($arguments);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain($message);
})->with([
    'ongeldige update' => [['safe-update', 'demo', '--only', 'plugin:../x'], 'Ongeldige update'],
    'openstaand werk' => [['safe-update', 'demo'], 'niet-gecommitte wijzigingen', 'themes/demo/functions.php'],
]);

it('verwijst bij Laravel naar pakketten bijwerken', function (): void {
    $this->server->laravelSite();

    $result = $this->server->run(['safe-update', 'shop']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Laravel-site', 'wpopen upgrade shop');
});
