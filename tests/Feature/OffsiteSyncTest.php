<?php

use App\Enums\OffsiteStatus;
use App\Jobs\RestoreOffsiteBackup;
use App\Jobs\SyncOffsiteBackups;
use App\Models\OffsiteBackup;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\Offsite\BackupCipher;
use App\Services\Offsite\OffsiteRestorer;
use App\Services\Offsite\OffsiteSync;
use App\Services\Offsite\S3StorageFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeS3;
use Tests\Support\OffsiteFixtures;

beforeEach(function (): void {
    Sleep::fake();
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(15, 0));
    $this->sites = OffsiteFixtures::sitesDirectory();
    $this->s3 = (new FakeS3)->fake();
    $this->notifier = $this->spy(DesktopNotifier::class);
    OffsiteFixtures::configure($this->s3);
});

afterEach(function (): void {
    File::deleteDirectory($this->sites);
});

it('uploadt afgeronde back-ups versleuteld en slaat nog-bezige over', function (): void {
    OffsiteFixtures::backup('klant-site', '20261014-100000-push', ['local-db.sql.gz' => 'geheime klantdata', 'live-files/wp-content/a.php' => '<?php']);
    OffsiteFixtures::backup('klant-site', '20261014-145900-manual', mtime: now()->subSeconds(30)->getTimestamp());

    $result = app(OffsiteSync::class)->run();

    expect($result)->toMatchArray(['uploaded' => 1, 'failed' => 0]);

    $object = $this->s3->objects['bd-deck/klant-site/20261014-100000-push.tar.enc'] ?? null;
    expect($object)->not->toBeNull()
        ->and($object['body'])->toStartWith(BackupCipher::MAGIC)
        ->and(str_contains($object['body'], 'geheime klantdata'))->toBeFalse()
        ->and($object['metadata']['key-id'])->toBe(app(BackupCipher::class)->keyId(OffsiteFixtures::PASSPHRASE))
        ->and($object['metadata']['backup-id'])->toBe('20261014-100000-push')
        ->and($this->s3->objects)->not->toHaveKey('bd-deck/klant-site/20261014-145900-manual.tar.enc');

    $row = OffsiteBackup::query()->sole();
    expect($row->status)->toBe(OffsiteStatus::Uploaded)
        ->and($row->size)->toBe(strlen($object['body']))
        ->and($row->kind)->toBe('push')
        ->and($row->uploaded_at)->not->toBeNull()
        ->and(File::files($this->sites.'/.wpopen-backups/.offsite-tmp'))->toBe([])
        ->and(app(AppSettings::class)->offsite()->lastSyncAt)->not->toBeNull();

    // tweede run: niets nieuws
    expect(app(OffsiteSync::class)->run()['uploaded'])->toBe(0);
});

it('uploadt grote back-ups in delen en zet ze daarna weer terug', function (): void {
    $this->app->instance(S3StorageFactory::class, new S3StorageFactory(partSize: 64 * 1024));
    $data = random_bytes(300 * 1024);
    OffsiteFixtures::backup('klant-site', '20261014-100000-push', ['local-db.sql.gz' => $data]);

    app(OffsiteSync::class)->run();

    $parts = array_filter($this->s3->log, fn (string $line): bool => str_contains($line, 'partNumber='));
    expect(count($parts))->toBeGreaterThanOrEqual(5)
        ->and($this->s3->uploads)->toBe([])
        ->and(OffsiteBackup::query()->sole()->status)->toBe(OffsiteStatus::Uploaded);

    // nieuwe laptop: lokale map weg, terughalen uit de cloud
    File::deleteDirectory($this->sites.'/.wpopen-backups/klant-site');
    $backup = OffsiteBackup::query()->sole();
    OffsiteRestorer::markQueued($backup->id);

    RestoreOffsiteBackup::dispatchSync($backup->id);

    expect(file_get_contents($this->sites.'/.wpopen-backups/klant-site/20261014-100000-push/local-db.sql.gz'))->toBe($data)
        ->and(OffsiteRestorer::state($backup->id))->toBeNull();
    $this->notifier->shouldHaveReceived('notice')->withArgs(fn (string $title): bool => $title === 'Back-up teruggehaald uit de cloud');
});

it('breekt een mislukte upload in delen netjes af', function (): void {
    $this->app->instance(S3StorageFactory::class, new S3StorageFactory(partSize: 64 * 1024));
    OffsiteFixtures::backup('klant-site', '20261014-100000-push', ['local-db.sql.gz' => random_bytes(200 * 1024)]);
    $this->s3->interceptor = fn ($request) => str_contains($request->url(), 'partNumber=2') ? $this->s3->error(403, 'AccessDenied') : null;

    app(OffsiteSync::class)->run();

    expect(OffsiteBackup::query()->sole()->status)->toBe(OffsiteStatus::Failed)
        ->and(OffsiteBackup::query()->sole()->error)->toContain('geen toegang')
        ->and($this->s3->uploads)->toBe([])
        ->and(collect($this->s3->log)->contains(fn (string $line): bool => str_starts_with($line, 'DELETE') && str_contains($line, 'uploadId=')))->toBeTrue();
});

it('meldt mislukte uploads één keer per reeks', function (): void {
    OffsiteFixtures::backup('klant-site', '20261014-100000-push');
    $this->s3->interceptor = fn () => $this->s3->error(403, 'InvalidAccessKeyId');

    app(OffsiteSync::class)->run();
    app(OffsiteSync::class)->run();

    $row = OffsiteBackup::query()->sole();
    expect($row->status)->toBe(OffsiteStatus::Failed)->and($row->error)->toBe('De sleutel klopt niet. Controleer de access key ID en de geheime sleutel.');
    $this->notifier->shouldHaveReceived('offsiteBackupFailed')->once();

    // hersteld: de volgende mislukking mag weer melden
    $this->s3->interceptor = null;
    app(OffsiteSync::class)->run();
    expect($row->fresh()->status)->toBe(OffsiteStatus::Uploaded)
        ->and(app(AppSettings::class)->offsite()->failureNotified)->toBeFalse();
});

it('probeert tijdelijke storingen opnieuw', function (): void {
    OffsiteFixtures::backup('klant-site', '20261014-100000-push');
    $failures = 0;
    $this->s3->interceptor = function () use (&$failures) {
        return $failures++ < 2 ? $this->s3->error(503, 'SlowDown') : null;
    };

    app(OffsiteSync::class)->run();

    expect(OffsiteBackup::query()->sole()->status)->toBe(OffsiteStatus::Uploaded);
    Sleep::assertSleptTimes(2);
});

it('ruimt de cloud op volgens de bewaarregels en uploadt niet wat meteen weg zou gaan', function (): void {
    OffsiteFixtures::configure($this->s3, ['keep_last' => 2, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0]);

    $old = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261001-100000-push')->create();
    $this->s3->seed($old->object_key, 'oud');
    $newer = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261010-100000-push')->create();
    $this->s3->seed($newer->object_key, 'nieuwer');

    OffsiteFixtures::backup('klant-site', '20261014-100000-push');
    OffsiteFixtures::backup('klant-site', '20260901-100000-push');

    $result = app(OffsiteSync::class)->run();

    expect($result)->toMatchArray(['uploaded' => 1, 'pruned' => 1])
        ->and($old->fresh()->status)->toBe(OffsiteStatus::Deleted)
        ->and($this->s3->objects)->not->toHaveKey($old->object_key)
        ->and($this->s3->objects)->toHaveKey($newer->object_key)
        ->and(OffsiteBackup::query()->where('backup_id', '20260901-100000-push')->exists())->toBeFalse();
});

it('ruimt lokaal alleen op wat aantoonbaar in de cloud staat, en nooit de nieuwste', function (): void {
    $safe = OffsiteFixtures::backup('klant-site', '20260901-100000-push');
    $missing = OffsiteFixtures::backup('klant-site', '20260902-100000-push');
    $recent = OffsiteFixtures::backup('klant-site', '20261013-100000-push');
    $onlyOne = OffsiteFixtures::backup('andere-site', '20260801-100000-push');

    app(OffsiteSync::class)->run();
    expect(OffsiteBackup::query()->inCloud()->count())->toBe(4);

    // een object dat stiekem uit de bucket verdween, telt niet als veilig
    unset($this->s3->objects['bd-deck/klant-site/20260902-100000-push.tar.enc']);
    OffsiteFixtures::configure($this->s3, ['cleanup_local' => true, 'cleanup_local_days' => 7]);

    $result = app(OffsiteSync::class)->run();

    expect($result['cleaned'])->toBe(1)
        ->and($safe)->not->toBeDirectory()
        ->and($missing)->toBeDirectory()
        ->and($recent)->toBeDirectory()
        ->and($onlyOne)->toBeDirectory();
});

it('doet niets als cloud-back-ups uit staan', function (): void {
    OffsiteFixtures::configure($this->s3, ['enabled' => false]);
    OffsiteFixtures::backup('klant-site', '20261014-100000-push');

    $this->artisan('backups:offsite')->expectsOutputToContain('staan uit')->assertSuccessful();

    expect(OffsiteBackup::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('zet de sync via het commando op de offsite-wachtrij', function (): void {
    Queue::fake();

    $this->artisan('backups:offsite')->assertSuccessful();

    Queue::assertPushedOn('offsite', SyncOffsiteBackups::class);
});

it('vindt back-ups in de bucket terug op een nieuwe laptop', function (): void {
    $cipher = app(BackupCipher::class);
    foreach (['20261001-100000-push', '20261002-100000-manual', '20261003-100000-rebuild'] as $id) {
        $this->s3->seed("bd-deck/klant-site/{$id}.tar.enc", $cipher->encryptString('x', OffsiteFixtures::PASSPHRASE), [
            'key-id' => $cipher->keyId(OffsiteFixtures::PASSPHRASE),
            'site' => rawurlencode('Klant Site'),
            'kind' => substr($id, 16),
        ]);
    }
    $this->s3->seed('bd-deck/andere-site/20261001-100000-push.tar.enc', 'x');
    $gone = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20260901-100000-push')->create();

    $found = app(OffsiteSync::class)->discover('klant-site', 'klant-site');

    expect($found)->toBe(3)
        ->and(OffsiteBackup::query()->forSite('klant-site')->inCloud()->pluck('backup_id')->sort()->values()->all())
        ->toBe(['20261001-100000-push', '20261002-100000-manual', '20261003-100000-rebuild'])
        ->and(OffsiteBackup::query()->where('backup_id', '20261002-100000-manual')->value('site_name'))->toBe('Klant Site')
        ->and(OffsiteBackup::query()->where('backup_id', '20261002-100000-manual')->value('kind'))->toBe('manual')
        ->and($gone->fresh()->status)->toBe(OffsiteStatus::Deleted)
        ->and(app(OffsiteSync::class)->discover('klant-site', 'klant-site'))->toBe(0);
});

it('haalt geen back-up terug die met een ander wachtwoord versleuteld is', function (): void {
    $backup = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261001-100000-push')->create(['key_id' => 'abcdefabcdefabcd']);
    OffsiteRestorer::markQueued($backup->id);

    RestoreOffsiteBackup::dispatchSync($backup->id);

    expect(OffsiteRestorer::state($backup->id))->toMatchArray(['state' => 'failed'])
        ->and(OffsiteRestorer::state($backup->id)['error'])->toContain('ander wachtwoord');
    Http::assertNothingSent();
});

it('ruimt een mislukte download op en laat geen halve back-up achter', function (): void {
    $backup = OffsiteBackup::factory()->uploaded()->forBackup('klant-site', '20261001-100000-push')->create();
    $this->s3->seed($backup->object_key, 'geen geldige back-up');

    RestoreOffsiteBackup::dispatchSync($backup->id);

    expect(OffsiteRestorer::state($backup->id)['state'])->toBe('failed')
        ->and($this->sites.'/.wpopen-backups/klant-site/20261001-100000-push')->not->toBeDirectory()
        ->and(File::files($this->sites.'/.wpopen-backups/.offsite-tmp'))->toBe([]);
    Cache::flush();
});
