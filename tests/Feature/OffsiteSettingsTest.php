<?php

use App\Jobs\SyncOffsiteBackups;
use App\Livewire\Settings\OffsiteBackups;
use App\Models\OffsiteBackup;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\Offsite\BackupCipher;
use App\Services\Vault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeS3;
use Tests\Support\OffsiteFixtures;

beforeEach(function (): void {
    $user = User::factory()->owner('oud-wachtwoord-123')->create();
    app(Vault::class)->unlock($user, 'oud-wachtwoord-123');
    $this->actingAs($user);
    Sleep::fake();
    $this->sites = OffsiteFixtures::sitesDirectory();
    $this->s3 = (new FakeS3)->fake();
});

afterEach(function (): void {
    File::deleteDirectory($this->sites);
});

function fillOffsiteForm(FakeS3 $s3): Testable
{
    return Livewire::test(OffsiteBackups::class)
        ->set('enabled', true)
        ->set('provider', 'backblaze-b2')
        ->set('bucket', $s3->bucket)
        ->set('accessKeyId', $s3->accessKeyId)
        ->set('secretAccessKey', $s3->secretAccessKey)
        ->set('passphrase', OffsiteFixtures::PASSPHRASE)
        ->set('passphrase_confirmation', OffsiteFixtures::PASSPHRASE);
}

it('staat op de instellingenpagina', function (): void {
    Process::fake(['*' => Process::result('[]')]);

    $this->get(route('settings.index'))->assertSeeLivewire(OffsiteBackups::class)->assertSee('Back-ups in de cloud');
});

it('vult endpoint en regio in per dienst', function (): void {
    Livewire::test(OffsiteBackups::class)
        ->set('provider', 'wasabi')
        ->assertSet('region', 'eu-central-1')
        ->assertSet('endpoint', 'https://s3.eu-central-1.wasabisys.com')
        ->set('region', 'us-east-2')
        ->assertSet('endpoint', 'https://s3.us-east-2.wasabisys.com')
        ->set('provider', 'cloudflare-r2')
        ->assertSet('region', 'auto')
        ->assertSet('endpoint', '')
        ->assertSee('Manage API tokens');
});

it('slaat op, versleutelt de geheimen en stuurt ze nooit terug naar de browser', function (): void {
    fillOffsiteForm($this->s3)->call('save')->assertHasNoErrors()
        ->assertSet('secretAccessKey', '')
        ->assertSet('passphrase', '')
        ->assertDontSee($this->s3->secretAccessKey)
        ->assertDontSee(OffsiteFixtures::PASSPHRASE)
        ->assertSee('Opgeslagen. Laat leeg om hem te houden.');

    $stored = app(AppSettings::class)->get('offsite_backups');
    expect(json_encode($stored))->not->toContain($this->s3->secretAccessKey)->not->toContain(OffsiteFixtures::PASSPHRASE)
        ->and($stored['key_id'])->toBe(app(BackupCipher::class)->keyId(OffsiteFixtures::PASSPHRASE));

    $config = app(AppSettings::class)->offsite();
    expect($config->isActive())->toBeTrue()
        ->and($config->secretAccessKey)->toBe($this->s3->secretAccessKey)
        ->and($config->passphrase)->toBe(OffsiteFixtures::PASSPHRASE)
        ->and($config->endpoint)->toBe('https://s3.eu-central-003.backblazeb2.com')
        ->and(print_r($config, true))->not->toContain($this->s3->secretAccessKey);

    // opnieuw openen en opslaan zonder geheimen in te vullen houdt ze
    Livewire::test(OffsiteBackups::class)
        ->assertSet('secretAccessKey', '')
        ->assertSet('bucket', $this->s3->bucket)
        ->set('keepLast', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AppSettings::class)->offsite()->secretAccessKey)->toBe($this->s3->secretAccessKey)
        ->and(app(AppSettings::class)->offsite()->retention->keepLast)->toBe(5);
});

it('valideert de velden', function (): void {
    Livewire::test(OffsiteBackups::class)
        ->set('enabled', true)
        ->set('endpoint', 'geen-adres')
        ->set('bucket', 'a')
        ->set('passphrase', 'kort')
        ->set('passphrase_confirmation', 'anders')
        ->set('keepLast', 0)
        ->set('prefix', '../weg')
        ->call('save')
        ->assertHasErrors(['endpoint', 'bucket', 'accessKeyId', 'secretAccessKey', 'passphrase', 'keepLast', 'prefix']);

    expect(app(AppSettings::class)->get('offsite_backups'))->toBeNull();
});

it('mag uit staan zonder dat alles ingevuld is', function (): void {
    Livewire::test(OffsiteBackups::class)->set('enabled', false)->call('save')->assertHasNoErrors();

    expect(app(AppSettings::class)->offsite()->enabled)->toBeFalse();
});

it('beschrijft de bewaarregels in gewone taal terwijl je typt', function (): void {
    Livewire::test(OffsiteBackups::class)
        ->assertSee('Altijd de laatste 3 back-ups, plus één per dag voor 7 dagen, één per week voor 4 weken en één per maand voor 6 maanden.')
        ->set('keepWeekly', 0)
        ->set('keepMonthly', 1)
        ->assertSee('Altijd de laatste 3 back-ups, plus één per dag voor 7 dagen en één per maand voor 1 maand.')
        ->set('keepDaily', 0)
        ->set('keepMonthly', 0)
        ->set('keepLast', 1)
        ->assertSee('Altijd de laatste back-up.');
});

it('waarschuwt bij een nieuw versleutelwachtwoord dat oude back-ups het oude houden', function (): void {
    fillOffsiteForm($this->s3)->call('save');
    $oldKeyId = app(AppSettings::class)->offsite()->keyId;
    OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261001-100000-push')->create(['key_id' => $oldKeyId]);

    Livewire::test(OffsiteBackups::class)
        ->set('passphrase', 'een heel ander wachtwoord')
        ->assertSee('Back-ups die al in de cloud staan houden het oude wachtwoord.')
        ->set('passphrase_confirmation', 'een heel ander wachtwoord')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', title: 'Nieuw versleutelwachtwoord')
        ->assertSee('1 back-up in de cloud is versleuteld met een eerder wachtwoord.');

    expect(app(AppSettings::class)->offsite()->keyId)->not->toBe($oldKeyId);
});

it('test de verbinding met een versleuteld testbestand en ruimt het op', function (): void {
    fillOffsiteForm($this->s3)
        ->call('testConnection')
        ->assertHasNoErrors()
        ->assertSet('connectionResult.ok', true)
        ->assertSee('Schrijven, lezen en verwijderen in bd-test lukt.');

    expect($this->s3->objects)->toBe([])
        ->and(collect($this->s3->log)->map(fn (string $line): string => strtok($line, ' '))->all())->toBe(['PUT', 'GET', 'DELETE']);
});

it('vertaalt verbindingsfouten naar begrijpelijk Nederlands', function (Closure $failure, string $message): void {
    $this->s3->interceptor = $failure->bindTo($this);

    fillOffsiteForm($this->s3)->call('testConnection')->assertSet('connectionResult.ok', false)->assertSee($message);
})->with([
    'foute sleutel' => [fn () => $this->s3->error(403, 'SignatureDoesNotMatch'), 'De sleutel klopt niet.'],
    'onbekende sleutel' => [fn () => $this->s3->error(403, 'InvalidAccessKeyId'), 'De sleutel klopt niet.'],
    'geen rechten' => [fn () => $this->s3->error(403, 'AccessDenied'), 'Deze sleutel heeft geen toegang tot bucket bd-test.'],
    'bucket bestaat niet' => [fn () => $this->s3->error(404, 'NoSuchBucket'), 'De bucket bd-test bestaat niet.'],
    'andere regio' => [fn () => $this->s3->error(301, 'PermanentRedirect'), 'staat in een andere regio'],
    'onbereikbaar' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host'), 'Kan s3.eu-central-003.backblazeb2.com niet bereiken.'],
]);

it('start synchroniseren alleen als alles ingesteld is', function (): void {
    Queue::fake();

    Livewire::test(OffsiteBackups::class)->call('syncNow')->assertDispatched('toast', tone: 'danger');
    Queue::assertNothingPushed();

    fillOffsiteForm($this->s3)->call('save')->call('syncNow')->assertDispatched('toast', title: 'Synchroniseren gestart');
    Queue::assertPushedOn('offsite', SyncOffsiteBackups::class);
});

it('toont de status van de laatste sync', function (): void {
    fillOffsiteForm($this->s3)->call('save');
    OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261001-100000-push')->create(['size' => 2 * 1024 * 1024]);
    OffsiteBackup::factory()->failed('Kan s3.example.com niet bereiken.')->forBackup('klant-site', '20261002-100000-push')->create();
    app(AppSettings::class)->updateOffsite(['last_sync_at' => now()->subMinutes(5)->toIso8601String()]);

    Livewire::test(OffsiteBackups::class)
        ->assertSee('5 minuten geleden')
        ->assertSee('1 back-up')
        ->assertSee('2.0 MB')
        ->assertSee('Kan s3.example.com niet bereiken.');

    Http::assertNothingSent();
});
