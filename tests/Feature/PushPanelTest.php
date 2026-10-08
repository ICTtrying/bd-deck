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
        ->assertSet('files', ['themes/demo/functions.php', 'themes/demo/style.css'])
        ->assertSee('themes/demo/style.css');
});

it('vraagt een commitbericht als er niet-gecommitte wijzigingen zijn', function (): void {
    $site = Site::factory()->built()->snapshotState(['git' => ['dirty' => 2]])->create();

    Livewire::test(PushPanel::class, ['site' => $site])
        ->call('open')
        ->call('push')
        ->assertHasErrors('message');

    Queue::assertNothingPushed();
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
