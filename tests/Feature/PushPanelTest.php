<?php

use App\Jobs\RunWpOpenCommand;
use App\Livewire\Sites\PushPanel;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->owner()->create());
    Process::fake(['*' => Process::result("Bestanden naar live (2):\n  ↑ themes/demo/functions.php\n  ↑ themes/demo/style.css\n✓ Proefrun: er is niets veranderd.\n")]);
});

it('toont uit de proefrun welke bestanden live gaan', function (): void {
    $site = Site::factory()->built()->create();

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->assertDispatched('open-modal', name: 'push')
        ->assertSet('files', ['themes/demo/functions.php', 'themes/demo/style.css'])
        ->assertSee('themes/demo/style.css');
});

it('stelt een commitbericht voor, zodat live zetten één klik is', function (): void {
    $site = Site::factory()->built()->snapshotState(['git' => ['dirty' => 2]])->create(['name' => 'klant']);

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->assertSet('message', 'Aangepast: functions.php, style.css')
        ->set('message', '')
        ->call('push')
        ->assertHasNoErrors();

    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['push', 'klant', '--yes', '-m', 'Aangepast: functions.php, style.css']);
});

it('laat live-wijzigingen vooraf zien en zet pas live na een keuze', function (): void {
    Process::fake(['*' => Process::result("Bestanden naar live (1):\n  ↑ themes/demo/functions.php\nOp live aangepast sinds de laatste sync:\n    themes/demo/functions.php\n✓ Proefrun: er is niets veranderd.\n")]);
    $site = Site::factory()->built()->create(['name' => 'klant']);

    $component = Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->assertSet('changedOnLive', ['themes/demo/functions.php'])
        ->assertSee('Eerst live ophalen')
        ->call('push')
        ->assertHasErrors('force');

    Queue::assertNothingPushed();

    $component->set('force', true)->call('push')->assertHasNoErrors();

    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => in_array('--force', $job->run->arguments, true));
});

it('haalt live eerst op in plaats van te overschrijven', function (): void {
    Process::fake(['*' => Process::result("Bestanden naar live (1):\n  ↑ themes/demo/functions.php\nOp live aangepast sinds de laatste sync:\n    themes/demo/functions.php\n")]);
    $site = Site::factory()->built()->create(['name' => 'klant']);

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->call('pullFirst')
        ->assertDispatched('close-modal', name: 'push');

    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['pull', 'klant', '--code']);
});

it('toont wat er bij Laravel na het uploaden gebeurt', function (): void {
    Process::fake(['*' => Process::result("Bestanden naar live (1):\n  ↑ composer.lock\n· Composer-pakketten worden op live bijgewerkt (composer install --no-dev).\n✓ Proefrun: er is niets veranderd.\n")]);
    $site = Site::factory()->built()->create();

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->assertSet('steps', ['Composer-pakketten worden op live bijgewerkt (composer install --no-dev).'])
        ->assertSee('Wat er daarna gebeurt');
});

it('zet live met bericht en opties', function (): void {
    $site = Site::factory()->built()->snapshotState(['git' => ['dirty' => 1]])->create(['name' => 'klant']);

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->set('message', 'Telefoonnummer aangepast')
        ->set('uploads', true)
        ->call('push')
        ->assertHasNoErrors();

    Queue::assertPushedOn('default', RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => $job->run->arguments === ['push', 'klant', '--yes', '-m', 'Telefoonnummer aangepast', '--uploads']);
});

it('eist de sitenaam als bevestiging voor een database-push', function (): void {
    $site = Site::factory()->built()->create(['name' => 'klant']);

    $component = Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->set('database', true)
        ->set('confirmText', 'verkeerd')
        ->call('push')
        ->assertHasErrors('confirmText');

    Queue::assertNothingPushed();

    $component->set('confirmText', 'klant')->call('push')->assertHasNoErrors();

    Queue::assertPushed(RunWpOpenCommand::class, fn (RunWpOpenCommand $job): bool => in_array('--db', $job->run->arguments, true));
});
