<?php

namespace App\Livewire;

use App\Exceptions\DesktopLaunchException;
use App\Exceptions\WpOpenException;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\DesktopLauncher;
use App\Services\SshKeys;
use App\Services\WpOpen\WpOpen;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Eerste stappen voor wie de app net heeft: programma's, SSH-sleutel, sleutel bij de host, eerste site.
 */
#[Title('Aan de slag')]
class Onboarding extends Component
{
    /**
     * @var list<array{key: string, status: string, label: string, detail: string}>|null
     */
    public ?array $checks = null;

    protected SshKeys $keys;

    public function boot(SshKeys $keys): void
    {
        $this->keys = $keys;
    }

    public function runChecks(WpOpen $wpopen): void
    {
        try {
            $this->checks = array_values(array_filter((array) $wpopen->json(['setup', '--json'], 30), 'is_array'));
        } catch (WpOpenException $exception) {
            $this->dispatch('toast', title: __('Systeemcontrole mislukt'), message: $exception->getMessage(), tone: 'danger');
        }
    }

    /**
     * @return array{name: string, path: string, public_key: string, has_private_key: bool}|null
     */
    #[Computed]
    public function defaultKey(): ?array
    {
        return collect($this->keys->all())->firstWhere('is_default', true);
    }

    public function createKey(AppSettings $settings): void
    {
        if ($this->defaultKey !== null) {
            return;
        }

        try {
            $this->keys->generate(basename($settings->sshKeyPath()), get_current_user().'@'.gethostname());
        } catch (InvalidArgumentException|ProcessFailedException $exception) {
            $this->dispatch('toast', title: __('Sleutel maken mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        unset($this->defaultKey);
        $this->dispatch('toast', title: __('Sleutel aangemaakt'), message: __('Kopieer hem en zet hem bij je host.'), tone: 'success');
    }

    /**
     * Opent een terminal die alles installeert; de draaiende AppImage gaat mee, zodat hij ook in het menu komt.
     */
    public function runInstaller(DesktopLauncher $launcher, AppSettings $settings): void
    {
        $command = ['bash', base_path('bin/install-mint')];
        $appImage = getenv('APPIMAGE');

        if (is_string($appImage) && is_file($appImage)) {
            $command[] = $appImage;
        }

        try {
            $launcher->runInTerminal($settings->homeDirectory(), $command, __('BD Deck installeren'));
        } catch (DesktopLaunchException $exception) {
            $this->dispatch('toast', title: __('Terminal openen mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->dispatch('toast', title: __('Installatie gestart'), message: __('Volg de terminal; hij vraagt één keer je wachtwoord.'), tone: 'info');
    }

    /**
     * Eén regel voor collega's: haalt het script en de nieuwste release van GitHub.
     */
    public function installCommand(): string
    {
        return 'curl -fsSL https://raw.githubusercontent.com/'.config('app.repository').'/main/bin/install-mint | bash';
    }

    #[Computed]
    public function siteCount(): int
    {
        return Site::query()->count();
    }

    public function render(): View
    {
        $missing = collect($this->checks ?? [])->where('status', 'fail')->count();

        return view('livewire.onboarding', [
            'toolsReady' => $this->checks === null ? null : $missing === 0,
        ]);
    }
}
