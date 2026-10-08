<?php

namespace App\Livewire\Sites;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Livewire\Concerns\FillsConnectionFields;
use App\Livewire\Concerns\InteractsWithSites;
use App\Livewire\Forms\SiteForm;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Live zetten van een lokale site, of verhuizen van een bestaande site naar een nieuwe server.
 */
class Migrate extends Component
{
    use FillsConnectionFields;
    use InteractsWithSites;

    public Site $site;

    public SiteForm $form;

    public string $connection = '';

    public bool $keepOld = true;

    public bool $pullUploads = true;

    public string $confirmText = '';

    public string $newDomain = '';

    public function mount(Site $site): void
    {
        $this->site = $site;

        if ($site->isLaravel()) {
            session()->flash('toast', ['title' => __('Verhuizen kan nog niet bij Laravel'), 'message' => __('Zet Laravel-wijzigingen live met Naar live.'), 'tone' => 'info']);
            $this->redirectRoute('sites.show', $site, navigate: true);

            return;
        }

        // het formulier is voor de nieuwe server; alleen de naam hoort bij de bestaande site
        $this->form->site = $site;
        $this->form->name = $site->name;
        $this->form->provider = SiteProvider::Hostinger->value;
    }

    public function migrate(CommandRunner $runner): void
    {
        $this->form->validate();
        $this->validate(
            ['form.liveUrl' => ['required', 'url:https,http', 'regex:#^https?://[A-Za-z0-9.-]+/?$#']],
            ['form.liveUrl.required' => __('Vul het adres in waarop de nieuwe site bereikbaar is.'), 'form.liveUrl.regex' => __('Gebruik een adres zonder pad, bijvoorbeeld https://klant.nl')],
            ['form.liveUrl' => __('live-adres')],
        );

        if ($this->confirmText !== $this->site->name) {
            $this->addError('confirmText', __('Typ de naam van de site om te bevestigen.'));

            return;
        }

        if (! $this->site->isBuilt()) {
            $this->addError('confirmText', __(':site is nog niet lokaal gebouwd. Kies eerst "Lokaal bouwen".', ['site' => $this->site->name]));

            return;
        }

        $this->attempt(function () use ($runner): void {
            $command = WpOpenCommand::migrate(
                site: $this->site,
                target: $this->form->target(),
                liveUrl: $this->form->liveUrl,
                sshOptions: $this->form->sshOptions(),
                remotePath: $this->form->discover ? null : $this->form->remotePath,
                mode: $this->form->discover ? null : SiteMode::from($this->form->mode),
                provider: SiteProvider::from($this->form->provider),
                keepOld: $this->keepOld,
                pullUploads: $this->pullUploads,
            );

            // het wachtwoord gaat via de omgeving naar ssh-copy-id, nooit als argument
            $run = $this->queueCommand($runner, $command, $this->form->password !== '' ? ['WPO_PASS' => $this->form->password] : []);
            $this->form->rememberPassword($this->site->name.' (SSH, nieuwe server)');

            $this->redirectRoute('activity.show', $run, navigate: true);
        });
    }

    public function changeDomain(CommandRunner $runner): void
    {
        $this->validate(
            ['newDomain' => ['required', 'url:https,http', 'regex:#^https?://[A-Za-z0-9.-]+/?$#']],
            ['newDomain.regex' => __('Gebruik een adres zonder pad, bijvoorbeeld https://klant.nl')],
            ['newDomain' => __('domein')],
        );

        $this->attempt(function () use ($runner): void {
            $run = $this->queueCommand($runner, WpOpenCommand::changeDomain($this->site, $this->newDomain));
            $this->redirectRoute('activity.show', $run, navigate: true);
        });
    }

    public function render(): View
    {
        return view('livewire.sites.migrate', [
            'providers' => SiteProvider::cases(),
            'modes' => SiteMode::remote(),
        ])->title($this->site->isLocalOnly()
            ? __(':site live zetten', ['site' => $this->site->name])
            : __(':site verhuizen', ['site' => $this->site->name]));
    }
}
