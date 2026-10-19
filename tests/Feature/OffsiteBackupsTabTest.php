<?php

use App\Jobs\RestoreOffsiteBackup;
use App\Livewire\Sites\Backups;
use App\Models\OffsiteBackup;
use App\Models\Site;
use App\Models\User;
use App\Services\Offsite\BackupCipher;
use App\Services\Offsite\OffsiteRestorer;
use App\Services\Vault;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
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
    $this->site = Site::factory()->create(['name' => 'klant-site']);

    $local = fn (string $id): array => ['id' => $id, 'kind' => substr($id, 16), 'created' => '', 'size' => 1024, 'parts' => ['local-db.sql.gz']];
    Process::fake(['*backups*' => Process::result(json_encode([
        $local('20261014-100000-push'),
        $local('20261013-100000-push'),
        $local('20261012-100000-push'),
        $local('20261011-100000-push'),
    ]))]);
});

afterEach(function (): void {
    File::deleteDirectory($this->sites);
    Cache::flush();
});

it('toont geen cloudstatus als cloud-back-ups uit staan', function (): void {
    Livewire::test(Backups::class, ['site' => $this->site])
        ->assertDontSee('Alleen lokaal')
        ->assertDontSee('Cloud doorzoeken');
});

it('toont per back-up of hij in de cloud staat', function (): void {
    OffsiteFixtures::configure($this->s3);
    OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261014-100000-push')->create();
    OffsiteBackup::factory()->forBackup('klant-site', '20261013-100000-push')->create();
    OffsiteBackup::factory()->failed('Deze sleutel heeft geen toegang tot bucket bd-test.')->forBackup('klant-site', '20261012-100000-push')->create();

    Livewire::test(Backups::class, ['site' => $this->site])
        ->assertSeeInOrder(['In de cloud', 'Wordt geüpload', 'Mislukt', 'Deze sleutel heeft geen toegang tot bucket bd-test.', 'Alleen lokaal'])
        ->assertSeeHtml('wire:poll.5s');
});

it('toont back-ups die alleen in de cloud staan en haalt ze terug', function (): void {
    Queue::fake();
    OffsiteFixtures::configure($this->s3);
    $cloudOnly = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20260901-100000-manual')->create(['key_id' => app(BackupCipher::class)->keyId(OffsiteFixtures::PASSPHRASE)]);
    OffsiteBackup::factory()->uploaded()->forBackup('andere-site', '20260902-100000-push')->create();

    Livewire::test(Backups::class, ['site' => $this->site])
        ->assertSee('1 sep. 2026')
        ->assertSee('Terughalen')
        ->call('fetchFromCloud', $cloudOnly->id)
        ->assertDispatched('toast', title: 'Back-up wordt teruggehaald')
        ->assertSee('Wordt teruggehaald');

    Queue::assertPushedOn('offsite', RestoreOffsiteBackup::class, fn (RestoreOffsiteBackup $job): bool => $job->offsiteBackupId === $cloudOnly->id);
    expect(OffsiteRestorer::state($cloudOnly->id)['state'])->toBe('running');
});

it('haalt geen back-up van een andere site of met een ander wachtwoord terug', function (): void {
    Queue::fake();
    OffsiteFixtures::configure($this->s3);
    $otherSite = OffsiteBackup::factory()->uploaded()->forBackup('andere-site', '20260902-100000-push')->create();
    $oldKey = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20260901-100000-push')->create(['key_id' => 'abcdefabcdefabcd']);

    Livewire::test(Backups::class, ['site' => $this->site])
        ->assertSee('Versleuteld met een eerder wachtwoord')
        ->call('fetchFromCloud', $otherSite->id)
        ->call('fetchFromCloud', $oldKey->id)
        ->assertDispatched('toast', title: 'Ander versleutelwachtwoord');

    Queue::assertNothingPushed();
});

it('doorzoekt de cloud naar back-ups van deze site', function (): void {
    OffsiteFixtures::configure($this->s3);
    $this->s3->seed('bd-deck/klant-site/20260801-100000-push.tar.enc', 'x', ['site' => 'klant-site', 'kind' => 'push']);

    Livewire::test(Backups::class, ['site' => $this->site])
        ->assertSee('Alles uit de cloud staat ook op deze laptop.')
        ->call('searchCloud')
        ->assertDispatched('toast', title: '1 back-up gevonden in de cloud')
        ->assertSee('1 aug. 2026');
});

it('meldt een fout bij doorzoeken in gewone taal', function (): void {
    OffsiteFixtures::configure($this->s3);
    $this->s3->interceptor = fn () => $this->s3->error(404, 'NoSuchBucket');

    Livewire::test(Backups::class, ['site' => $this->site])
        ->call('searchCloud')
        ->assertDispatched('toast', title: 'Cloud doorzoeken mislukt', message: 'De bucket bd-test bestaat niet. Controleer de naam of maak hem eerst aan bij je opslagdienst.');
});
