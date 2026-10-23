<?php

namespace App\Livewire\Sites;

use App\Enums\CredentialKind;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Credential;
use App\Models\Site;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Instellingen per Shopify-project: winkel, map, poort, bescherming van klantbestanden en het storefront-wachtwoord.
 */
class ShopifySettings extends Component
{
    use InteractsWithSites;

    public Site $site;

    public string $store = '';

    public string $path = '';

    public int $port = 9292;

    public string $host = '127.0.0.1';

    public bool $themeEditorSync = false;

    public string $liveUrl = '';

    /**
     * Eén patroon per regel: bestanden die een push nooit overschrijft.
     */
    public string $protect = '';

    public bool $protectionDisabled = false;

    public string $storePassword = '';

    public function mount(): void
    {
        $this->fill([
            'store' => $this->site->shopifyStore(),
            'path' => $this->site->projectDirectory(),
            'port' => $this->site->devPort(),
            'host' => (string) $this->site->data('shopify.host', '127.0.0.1'),
            'themeEditorSync' => (bool) $this->site->data('shopify.theme_editor_sync', false),
            'liveUrl' => (string) $this->site->data('live_url'),
            'protect' => implode("\n", $this->site->protectedPatterns()),
            'protectionDisabled' => (bool) $this->site->data('shopify.protection_disabled', false),
        ]);
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        return Str::of($this->protect)->explode("\n")->map(fn (string $line): string => trim($line))->filter()->unique()->values()->all();
    }

    public function save(WpOpen $wpopen, SiteRegistry $registry): void
    {
        $this->validate([
            'store' => ['required', 'string', 'max:120'],
            'path' => ['required', 'string', 'max:500', 'starts_with:/', 'not_regex:/[|\n\r]|\.\./'],
            'port' => ['required', 'integer', 'between:1024,65535'],
            'host' => ['required', Rule::in(['127.0.0.1', '0.0.0.0'])],
            'themeEditorSync' => ['boolean'],
            'liveUrl' => ['nullable', 'url:https,http', 'max:255'],
            'protect' => ['nullable', 'string', 'max:1000'],
            'storePassword' => ['nullable', 'string', 'max:255'],
        ], [
            'path.starts_with' => __('Gebruik een volledig pad, beginnend met /'),
            'liveUrl.url' => __('Gebruik een volledig adres, bijvoorbeeld https://klant.nl'),
        ], [
            'store' => __('winkel'),
            'path' => __('projectmap'),
            'port' => __('poort'),
            'liveUrl' => __('eigen domein'),
        ]);

        if ($this->protectionDisabled === false && $this->patterns() === []) {
            $this->addError('protect', __('Geef minstens één bestand of patroon op, of zet de bescherming bewust uit.'));

            return;
        }

        $this->attempt(function () use ($wpopen, $registry): void {
            $arguments = WpOpenCommand::shopifyUpdate(
                $this->site,
                store: $this->store,
                path: $this->path,
                port: $this->port,
                liveUrl: rtrim(trim($this->liveUrl), '/'),
                // lege lijst = bescherming uit; een lijst gelijk aan de standaard wordt hier niet speciaal behandeld
                ignore: $this->protectionDisabled ? [] : $this->patterns(),
                themeEditorSync: $this->themeEditorSync,
                host: $this->host,
            );

            $wpopen->runOrFail($arguments, 30);

            if ($this->storePassword !== '') {
                $this->rememberStorePassword();
            }

            $this->site = $registry->refresh($this->site) ?? $this->site;
            $this->reset('storePassword');
            $this->dispatch('sites-changed');
            $this->dispatch('toast', title: __('Opgeslagen'), message: $this->site->isRunning() ? __('Een nieuwe poort, host of winkel werkt pas na een herstart van de dev server.') : $this->site->name, tone: 'success');
        });
    }

    /**
     * Versleuteld in de kluis; de dev server krijgt het alleen via de omgeving.
     */
    private function rememberStorePassword(): void
    {
        Credential::query()->updateOrCreate(
            ['site_id' => $this->site->id, 'kind' => CredentialKind::ShopifyStorePassword],
            ['label' => $this->site->name.' (winkelwachtwoord)', 'secret' => $this->storePassword, 'url' => 'https://'.$this->site->shopifyStore()],
        );
    }

    public function forgetStorePassword(): void
    {
        $this->site->credentials()->where('kind', CredentialKind::ShopifyStorePassword)->delete();
        $this->dispatch('toast', title: __('Wachtwoord verwijderd uit de kluis'), tone: 'info');
    }

    /**
     * De standaard weer terugzetten.
     */
    public function resetProtection(): void
    {
        $this->protect = implode("\n", ['config/settings_data.json', 'templates/*.json', 'sections/*.json']);
        $this->protectionDisabled = false;
    }

    public function render(): View
    {
        return view('livewire.sites.shopify-settings', ['hasPassword' => $this->site->storePasswordCredential() !== null]);
    }
}
