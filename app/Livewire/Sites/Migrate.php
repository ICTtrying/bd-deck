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
use Illuminate\Support\Str;
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

    /**
     * Multisite: live-adres per subsite-id (lokale adressen bevatten punten, en die leest Livewire als sleutelpad).
     * Leeg = de naam vóór het lokale adres plus het live-domein.
     *
     * @var array<int, string>
     */
    public array $subsiteHosts = [];

    public function mount(Site $site): void
    {
        $this->site = $site;

        if ($site->isLaravel()) {
            session()->flash('toast', ['title' => __('Verhuizen kan nog niet bij Laravel'), 'message' => __('Zet Laravel-wijzigingen live met Naar live.'), 'tone' => 'info']);
            $this->redirectRoute('sites.show', $site, navigate: true);

            return;
        }

        foreach ($site->subsites() as $subsite) {
            $this->subsiteHosts[$subsite['id']] = '';
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

        if ($this->site->isMultisite() && ! $this->validateSubsiteHosts()) {
            return;
        }

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
                subsiteHosts: $this->normalizedSubsiteHosts(),
            );

            // het wachtwoord gaat via de omgeving naar ssh-copy-id, nooit als argument
            $run = $this->queueCommand($runner, $command, $this->form->password !== '' ? ['WPO_PASS' => $this->form->password] : []);
            $this->form->rememberPassword($this->site->name.' (SSH, nieuwe server)');

            $this->redirectRoute('activity.show', $run, navigate: true);
        });
    }

    /**
     * Het voorstel voor een subsite zonder ingevuld adres: winkel.<lokaal> → winkel.<live-domein>.
     */
    public function suggestedSubsiteHost(string $localHost): ?string
    {
        $suffix = '.'.$this->site->localHost();
        $liveHost = (string) parse_url($this->form->liveUrl, PHP_URL_HOST);

        if (! str_ends_with($localHost, $suffix) || $liveHost === '') {
            return null;
        }

        return Str::beforeLast($localHost, $suffix).'.'.Str::lower($liveHost);
    }

    /**
     * @return array<int, string> subsite-id → lokaal adres
     */
    public function localSubsiteHosts(): array
    {
        return collect($this->site->subsites())
            ->mapWithKeys(fn (array $subsite): array => [$subsite['id'] => (string) parse_url($subsite['url'], PHP_URL_HOST)])
            ->all();
    }

    /**
     * @return array<string, string> lokaal adres → ingevuld live-adres
     */
    private function normalizedSubsiteHosts(): array
    {
        $local = $this->localSubsiteHosts();

        return collect($this->subsiteHosts)
            ->filter(fn (string $host, int $id): bool => isset($local[$id]))
            ->mapWithKeys(fn (string $host, int $id): array => [$local[$id] => Str::of($host)->trim()->lower()->after('://')->before('/')->toString()])
            ->filter()
            ->all();
    }

    private function validateSubsiteHosts(): bool
    {
        if (! $this->form->discover && $this->form->mode === SiteMode::Sftp->value) {
            $this->addError('form.mode', __('Een multisite live zetten kan alleen via SSH.'));

            return false;
        }

        $hosts = $this->normalizedSubsiteHosts();
        $hostPattern = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';
        $valid = true;

        foreach ($this->localSubsiteHosts() as $id => $localHost) {
            $liveHost = $hosts[$localHost] ?? $this->suggestedSubsiteHost($localHost);

            $error = match (true) {
                $liveHost === null => __('Vul het live-adres in, bijvoorbeeld klant2.nl.'),
                ! preg_match($hostPattern, $liveHost) => __('Gebruik een adres zonder pad, bijvoorbeeld klant2.nl.'),
                $liveHost === Str::lower((string) parse_url($this->form->liveUrl, PHP_URL_HOST)) => __('Dit is het adres van de hoofdsite.'),
                default => null,
            };

            if ($error !== null) {
                $this->addError('subsiteHosts.'.$id, $error);
                $valid = false;
            }
        }

        return $valid;
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
