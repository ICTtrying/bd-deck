<?php

namespace App\Livewire\Sites;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Livewire\Concerns\FillsConnectionFields;
use App\Livewire\Concerns\InteractsWithSites;
use App\Livewire\Forms\SiteForm;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\PremiumLibrary;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Site toevoegen')]
class Create extends Component
{
    use FillsConnectionFields;
    use InteractsWithSites;

    public SiteForm $form;

    protected PremiumLibrary $library;

    public function boot(PremiumLibrary $library): void
    {
        $this->library = $library;
    }

    public string $connection = '';

    /**
     * bestaand: een live-site koppelen; lokaal: een nieuwe WordPress-site zonder server.
     */
    #[Url(as: 'soort', except: 'bestaand')]
    public string $kind = 'bestaand';

    public string $localName = '';

    public string $localTitle = '';

    public bool $localMultisite = false;

    /**
     * Aangevinkte zips uit de premium-map, als 'theme:slug' of 'plugin:slug'; standaard allemaal.
     *
     * @var list<string>
     */
    public array $premium = [];

    /**
     * @var list<string>
     */
    public array $plugins = [];

    /**
     * Andere wordpress.org-plugins, gescheiden door komma's of spaties.
     */
    public string $extraPlugins = '';

    public function mount(): void
    {
        $this->premium = $this->premiumPackages()->keys()->all();
    }

    /**
     * Per thema of plugin alleen de nieuwste zip, zoals wpopen die ook kiest.
     *
     * @return Collection<string, array{file: string, kind: ?string, slug: ?string, size: int, modified: int, active: bool}>
     */
    #[Computed]
    public function premiumPackages(): Collection
    {
        return $this->library->items()
            ->filter(fn (array $item): bool => $item['active'])
            ->sortBy(['kind', 'slug'])
            ->keyBy(fn (array $item): string => $item['kind'].':'.$item['slug']);
    }

    /**
     * @return list<string>
     */
    private function extraPluginSlugs(): array
    {
        return Str::of($this->extraPlugins)->lower()->explode(' ')
            ->flatMap(fn (string $part): array => explode(',', $part))
            ->map(fn (string $slug): string => trim($slug))
            ->filter()
            ->values()
            ->all();
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
            'premium' => ['array'],
            'premium.*' => ['string', Rule::in($this->premiumPackages()->keys())],
            'plugins' => ['array'],
            'plugins.*' => ['string', Rule::in(array_keys(config('wordpress.suggested_plugins')))],
            'extraPlugins' => ['nullable', 'string', 'max:500'],
        ], [
            'localName.regex' => __('Gebruik alleen letters, cijfers, punt, - en _.'),
        ], [
            'localName' => __('naam'),
            'localTitle' => __('titel'),
        ]);

        $extraPlugins = $this->extraPluginSlugs();
        $invalid = collect($extraPlugins)->reject(fn (string $slug): bool => (bool) preg_match('/^[a-z0-9-]+$/', $slug));

        if ($invalid->isNotEmpty()) {
            $this->addError('extraPlugins', __('Ongeldige naam: :slug. Gebruik de naam uit de wordpress.org-link.', ['slug' => $invalid->first()]));
            $this->dispatch('open-modal', name: 'local-packages');

            return;
        }

        $this->attempt(function () use ($runner, $extraPlugins): void {
            // alles aangevinkt is het standaardgedrag van wpopen; zo pakt hij ook zips mee die net zijn toegevoegd
            $allPremium = array_diff($this->premiumPackages()->keys()->all(), $this->premium) === [];

            $run = $this->queueCommand($runner, WpOpenCommand::newSite(
                name: $this->localName,
                title: $this->localTitle,
                multisite: $this->localMultisite,
                premium: $allPremium ? null : array_values($this->premium),
                plugins: [...$this->plugins, ...$extraPlugins],
            ));

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
            'suggestedPlugins' => config('wordpress.suggested_plugins'),
        ]);
    }
}
