<?php

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Jobs\OpenLocalSite;
use App\Jobs\RunWpOpenCommand;
use App\Livewire\Dashboard;
use App\Livewire\Sites\MailpitChip;
use App\Livewire\Sites\ShareChip;
use App\Livewire\Sites\SharePanel;
use App\Livewire\Sites\Show;
use App\Models\CommandRun;
use App\Models\Site;
use App\Models\User;
use App\Services\DesktopLauncher;
use App\Services\DesktopNotifier;
use App\Services\SiteLinks;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->owner()->create());
});

/**
 * @return array{url: string, started_at: string, expires_at: string}
 */
function activeShare(int $minutesLeft = 192): array
{
    return [
        'url' => 'https://proef-123.trycloudflare.com',
        'started_at' => now()->subMinutes(10)->toIso8601String(),
        'expires_at' => now()->addMinutes($minutesLeft)->toIso8601String(),
    ];
}

describe('commando\'s', function (): void {
    it('bouwt de commando\'s om te delen en te stoppen', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);

        expect(WpOpenCommand::share($site, 480)->arguments)->toBe(['share', 'klant', '--minutes', '480'])
            ->and(WpOpenCommand::share($site, 480)->action)->toBe(WpOpenAction::Share)
            ->and(WpOpenCommand::shareStop($site)->arguments)->toBe(['share', 'klant', '--stop']);
    });

    it('weigert een vrije duur, een niet-gebouwde site en een multisite', function (Closure $site, int $minutes): void {
        WpOpenCommand::share($site(), $minutes);
    })->with([
        'duur' => [fn () => Site::factory()->built()->create(), 90],
        'niet gebouwd' => [fn () => Site::factory()->create(), 60],
        'multisite' => [fn () => Site::factory()->multisite()->create(), 60],
    ])->throws(InvalidArgumentException::class);

    it('telt een verlopen deellink in de snapshot niet meer mee', function (): void {
        $shared = Site::factory()->built()->snapshotState(['share' => activeShare()])->create();
        $expired = Site::factory()->built()->snapshotState(['share' => activeShare(-5)])->create();

        expect($shared->isShared())->toBeTrue()
            ->and($shared->shareUrl())->toBe('https://proef-123.trycloudflare.com')
            ->and($expired->isShared())->toBeFalse()
            ->and($expired->shareUrl())->toBeNull();
    });
});

describe('deelvenster', function (): void {
    it('maakt een link op de achtergrond met de gekozen duur', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);

        $component = Livewire::test(SharePanel::class, ['site' => $site])
            ->call('open')
            ->assertDispatched('open-modal', name: 'share')
            ->assertSee('Link maken')
            ->set('minutes', 480)
            ->call('start')
            ->assertHasNoErrors()
            ->assertSee('Link wordt gemaakt');

        Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['share', 'klant', '--minutes', '480']);
        expect($component->get('runId'))->toBe(CommandRun::query()->value('id'));
    });

    it('weigert een duur die niet in de lijst staat', function (): void {
        Livewire::test(SharePanel::class, ['site' => Site::factory()->built()->create()])
            ->set('minutes', 5)
            ->call('start')
            ->assertHasErrors('minutes');

        Queue::assertNothingPushed();
    });

    it('toont de link zodra de run klaar is', function (): void {
        $site = Site::factory()->built()->create(['name' => 'klant']);
        Process::fake(['*' => Process::result(json_encode([...$site->snapshot, 'share' => activeShare()]))]);

        $component = Livewire::test(SharePanel::class, ['site' => $site])->set('minutes', 60)->call('start');
        CommandRun::query()->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

        $component->call('poll')
            ->assertSet('runId', null)
            ->assertDispatched('sites-changed')
            ->assertSee('https://proef-123.trycloudflare.com');
    });

    it('toont een gedeelde site met link en een bericht voor de klant', function (): void {
        $this->travelTo(now()->setTime(13, 18));
        $site = Site::factory()->built()->snapshotState(['share' => activeShare()])->create();

        Livewire::test(SharePanel::class, ['site' => $site])
            ->assertSee('https://proef-123.trycloudflare.com')
            ->assertSee('Link stoppen')
            ->assertSee('Via deze link kun je de nieuwe versie van de website bekijken')
            ->assertSee('De link werkt tot vandaag 16:30.')
            ->assertDontSee('Link maken');
    });

    it('stopt de link direct en ververst de site', function (): void {
        $site = Site::factory()->built()->snapshotState(['share' => activeShare()])->create(['name' => 'klant']);
        Process::fake([
            '*info*' => Process::result(json_encode([...$site->snapshot, 'share' => null])),
            '*' => Process::result('✓ Deellink van klant gestopt'),
        ]);

        Livewire::test(SharePanel::class, ['site' => $site])
            ->call('stop')
            ->assertDispatched('toast', title: 'Deellink gestopt')
            ->assertDispatched('sites-changed')
            ->assertSee('Link maken');

        Process::assertRan(fn ($process): bool => array_slice($process->command, -3) === ['share', 'klant', '--stop']);
        expect($site->fresh()->isShared())->toBeFalse();
    });

    it('toont op de brug de resterende tijd of de knop om te delen', function (): void {
        $shared = Site::factory()->built()->snapshotState(['share' => activeShare()])->create();

        Livewire::test(ShareChip::class, ['site' => $shared])->assertSee('Gedeeld · nog '.CarbonInterval::minutes(192)->cascade()->forHumans(short: true, parts: 2));
        Livewire::test(ShareChip::class, ['site' => Site::factory()->built()->create()])->assertSee('Delen')->assertDontSee('Gedeeld');
    });

    it('zet delen en Mailpit alleen bij een gebouwde site op de brug', function (): void {
        $built = Site::factory()->built()->create();
        Process::fake(['*' => Process::result(json_encode($built->snapshot))]);

        Livewire::test(Show::class, ['site' => $built])
            ->assertSeeLivewire(ShareChip::class)
            ->assertSeeLivewire(SharePanel::class);

        $notBuilt = Site::factory()->create();
        Process::fake(['*' => Process::result(json_encode($notBuilt->snapshot))]);

        Livewire::test(Show::class, ['site' => $notBuilt])->assertDontSeeLivewire(ShareChip::class);
    });

    it('laat op het dashboard zien welke sites gedeeld worden', function (): void {
        Process::fake(['*' => Process::result('[]')]);
        Site::factory()->built()->snapshotState(['share' => activeShare()])->create(['name' => 'gedeeld']);

        Livewire::test(Dashboard::class)->assertSeeInOrder(['gedeeld', 'Gedeeld', 'Mailpit (testmails)']);
    });
});

describe('Mailpit', function (): void {
    it('neemt het Mailpit-adres van DDEV over, met de standaardpoort als terugval', function (): void {
        $links = app(SiteLinks::class);

        expect($links->mailpit(Site::factory()->built()->snapshotState(['mailpit_url' => 'https://klant.ddev.site:9026'])->create()))->toBe('https://klant.ddev.site:9026')
            ->and($links->mailpit(Site::factory()->built()->create(['name' => 'klant'])))->toBe('https://klant.ddev.site:8026');
    });

    it('opent Mailpit van een draaiende site direct', function (): void {
        Process::fake();
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(Dashboard::class)->call('launch', $site->id, 'mailpit');

        Queue::assertNotPushed(OpenLocalSite::class);
        Process::assertRan(fn ($process): bool => end($process->command) === 'https://klant.ddev.site:8026');
    });

    it('start een gestopte site eerst en opent Mailpit daarna', function (): void {
        $site = Site::factory()->built()->snapshotState(['ddev_status' => 'stopped'])->create(['name' => 'klant']);
        Process::fake([
            '*info*' => Process::result(json_encode([...$site->snapshot, 'ddev_status' => 'running'])),
            '*' => Process::result(''),
        ]);

        Livewire::test(Dashboard::class)->call('launch', $site->id, 'mailpit');
        Queue::assertPushedOn('quick', OpenLocalSite::class, fn (OpenLocalSite $job): bool => $job->target === 'mailpit');

        (new OpenLocalSite($site->id, 'mailpit'))->handle(
            app(WpOpen::class), app(SiteLinks::class), app(DesktopLauncher::class), app(SiteRegistry::class), app(DesktopNotifier::class),
        );

        Process::assertRan(fn ($process): bool => end($process->command) === 'https://klant.ddev.site:8026');
    });

    it('weigert Mailpit bij een site die nog niet gebouwd is', function (): void {
        Process::fake();
        $site = Site::factory()->create();

        Livewire::test(Dashboard::class)
            ->call('launch', $site->id, 'mailpit')
            ->assertDispatched('toast', tone: 'danger');

        Process::assertNothingRan();
    });

    it('toont het aantal ongelezen mails', function (): void {
        Http::fake(['klant.ddev.site:8026/api/v1/messages*' => Http::response(['total' => 7, 'unread' => 4])]);
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(MailpitChip::class, ['site' => $site])
            ->assertSet('unread', 4)
            ->assertSee('4 ongelezen mails');
    });

    it('vraagt Mailpit niets als de site niet draait of Mailpit niet antwoordt', function (): void {
        Http::fake(['*' => Http::response('', 502)]);

        Livewire::test(MailpitChip::class, ['site' => Site::factory()->built()->snapshotState(['ddev_status' => 'stopped'])->create()])->assertSet('unread', null);
        Http::assertNothingSent();

        Livewire::test(MailpitChip::class, ['site' => Site::factory()->built()->create()])->assertSet('unread', null)->assertSee('Mailpit');
    });

    it('stuurt een testmail via wpopen', function (): void {
        Http::fake(['*' => Http::response(['total' => 1, 'unread' => 1])]);
        Process::fake(['*' => Process::result('✓ Testmail verstuurd naar Mailpit')]);
        $site = Site::factory()->built()->create(['name' => 'klant']);

        Livewire::test(MailpitChip::class, ['site' => $site])
            ->call('sendTestMail')
            ->assertDispatched('toast', title: 'Testmail verstuurd');

        Process::assertRan(fn ($process): bool => array_slice($process->command, -3) === ['mailpit', 'klant', '--test']);
    });
});
