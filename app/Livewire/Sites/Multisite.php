<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\DesktopLauncher;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Snel een lokale WordPress-site omzetten naar multisite en subsites met een eigen adres toevoegen.
 */
class Multisite extends Component
{
    use InteractsWithSites;

    public Site $site;

    public string $address = '';

    public ?int $removing = null;

    public function convert(CommandRunner $runner): void
    {
        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::convertToMultisite($this->site)));
        $this->dispatch('close-modal', name: 'convert-multisite');
    }

    public function addSubsite(CommandRunner $runner): void
    {
        $this->validate(
            ['address' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/']],
            ['address.regex' => __('Gebruik een naam zoals winkel of een domein zoals klant2.test.')],
            ['address' => __('adres')],
        );

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::addSubsite($this->site, $this->address));
            $this->reset('address');
        });
    }

    public function confirmRemove(int $subsiteId): void
    {
        if ($this->subsite($subsiteId) === null) {
            return;
        }

        $this->removing = $subsiteId;
        $this->dispatch('open-modal', name: 'remove-subsite');
    }

    public function removeSubsite(CommandRunner $runner): void
    {
        if ($this->removing === null || $this->subsite($this->removing) === null) {
            return;
        }

        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::removeSubsite($this->site, $this->removing)));

        $this->removing = null;
        $this->dispatch('close-modal', name: 'remove-subsite');
    }

    /**
     * Alleen adressen uit de eigen subsitelijst, zodat er geen willekeurige URL geopend kan worden.
     */
    public function open(int $subsiteId, bool $admin, DesktopLauncher $launcher): void
    {
        $url = $this->subsite($subsiteId)['url'] ?? null;

        if ($url !== null) {
            $this->attempt(fn () => $launcher->openUrl(rtrim($url, '/').($admin ? '/wp-admin/' : '/')));
        }
    }

    #[On('sites-changed')]
    public function refreshSite(): void
    {
        $this->site->refresh();
        unset($this->busy);
    }

    #[Computed]
    public function busy(): bool
    {
        return $this->site->runs()->active()->exists();
    }

    /**
     * @return array{id: int, url: string}|null
     */
    private function subsite(int $subsiteId): ?array
    {
        return collect($this->site->subsites())->firstWhere('id', $subsiteId);
    }

    public function render(): View
    {
        return view('livewire.sites.multisite', [
            'removingUrl' => $this->removing !== null ? $this->subsite($this->removing)['url'] ?? null : null,
        ]);
    }
}
