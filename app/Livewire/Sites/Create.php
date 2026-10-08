<?php

namespace App\Livewire\Sites;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Livewire\Concerns\FillsConnectionFields;
use App\Livewire\Concerns\InteractsWithSites;
use App\Livewire\Forms\SiteForm;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Site toevoegen')]
class Create extends Component
{
    use FillsConnectionFields;
    use InteractsWithSites;

    public SiteForm $form;

    public string $connection = '';

    /**
     * bestaand: een live-site koppelen; lokaal: een nieuwe WordPress-site zonder server.
     */
    #[Url(as: 'soort', except: 'bestaand')]
    public string $kind = 'bestaand';

    public string $localName = '';

    public string $localTitle = '';

    public function save(CommandRunner $runner): void
    {
        $this->form->validate();

        $this->attempt(function () use ($runner): void {
            $command = WpOpenCommand::addSite(
                name: $this->form->name,
                target: $this->form->target(),
                sshOptions: $this->form->sshOptions(),
                remotePath: $this->form->discover ? null : $this->form->remotePath,
                mode: SiteMode::from($this->form->mode),
                provider: SiteProvider::from($this->form->provider),
                liveUrl: $this->form->liveUrl,
            );

            // het wachtwoord gaat via de omgeving naar ssh-copy-id, nooit als argument
            $run = $this->queueCommand($runner, $command, $this->form->password !== '' ? ['WPO_PASS' => $this->form->password] : []);

            $this->form->rememberPassword($this->form->name.' (SSH)');

            session()->flash('toast', ['title' => __('Site wordt toegevoegd'), 'message' => __('Hij verschijnt in de lijst zodra BD Deck hem op de server gevonden heeft.'), 'tone' => 'info']);
            $this->redirectRoute('dashboard', navigate: true);
        });
    }

    public function createLocal(CommandRunner $runner): void
    {
        $this->validate([
            'localName' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('sites', 'name')],
            'localTitle' => ['nullable', 'string', 'max:120', 'not_regex:/[|\n\r]/'],
        ], [
            'localName.regex' => __('Gebruik alleen letters, cijfers, punt, - en _.'),
        ], [
            'localName' => __('naam'),
            'localTitle' => __('titel'),
        ]);

        $this->attempt(function () use ($runner): void {
            $run = $this->queueCommand($runner, WpOpenCommand::newSite($this->localName, $this->localTitle));

            session()->flash('toast', ['title' => __('Lokale site wordt gemaakt'), 'message' => __('Dit duurt een paar minuten de eerste keer.'), 'tone' => 'info']);
            $this->redirectRoute('dashboard', navigate: true);
        });
    }

    public function import(CommandRunner $runner): void
    {
        $run = $this->queueCommand($runner, WpOpenCommand::import());
        $this->redirectRoute('activity.show', $run, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sites.create', [
            'providers' => SiteProvider::cases(),
            'modes' => SiteMode::remote(),
        ]);
    }
}
