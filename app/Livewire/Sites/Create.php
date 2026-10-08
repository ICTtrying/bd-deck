<?php

namespace App\Livewire\Sites;

use App\Enums\CredentialKind;
use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Livewire\Concerns\InteractsWithSites;
use App\Livewire\Forms\SiteForm;
use App\Models\Credential;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Site toevoegen')]
class Create extends Component
{
    use InteractsWithSites;

    public SiteForm $form;

    public string $connection = '';

    public function updatedConnection(string $value): void
    {
        $this->form->fillFromConnectionString($value);
    }

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

            if ($this->form->password !== '' && $this->form->savePassword) {
                Credential::query()->create([
                    'label' => $this->form->name.' (SSH)',
                    'kind' => CredentialKind::SshPassword,
                    'username' => $this->form->target(),
                    'secret' => $this->form->password,
                ]);
            }

            session()->flash('toast', ['title' => __('Site wordt toegevoegd'), 'message' => __('Je ziet hem op het dashboard zodra de verbinding gelukt is.'), 'tone' => 'info']);
            $this->redirectRoute('activity.show', $run, navigate: true);
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
            'modes' => SiteMode::cases(),
        ]);
    }
}
