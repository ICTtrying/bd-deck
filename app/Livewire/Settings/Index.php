<?php

namespace App\Livewire\Settings;

use App\Enums\TerminalApp;
use App\Enums\ThemePreference;
use App\Exceptions\WpOpenException;
use App\Services\AppSettings;
use App\Services\Vault;
use App\Services\WpOpen\ScriptInstaller;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Instellingen')]
class Index extends Component
{
    public string $locale = 'nl';

    public string $theme = 'system';

    public string $sitesDirectory = '';

    public string $scriptPath = '';

    public string $editor = 'code';

    public string $terminal = 'gnome-terminal';

    public string $sshKeyPath = '';

    public bool $autoLoginLocal = true;

    public bool $autoLoginLive = true;

    public int $autoLockMinutes = 30;

    public bool $notifications = true;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    /**
     * @var list<array{key: string, status: string, label: string, detail: string}>
     */
    public array $checks = [];

    public function mount(AppSettings $settings): void
    {
        $this->fill([
            'locale' => $settings->locale(),
            'theme' => $settings->theme()->value,
            'sitesDirectory' => $settings->sitesDirectory(),
            'scriptPath' => $settings->scriptPath(),
            'editor' => $settings->editor(),
            'terminal' => $settings->terminal()->value,
            'sshKeyPath' => $settings->sshKeyPath(),
            'autoLoginLocal' => $settings->autoLoginLocal(),
            'autoLoginLive' => $settings->autoLoginLive(),
            'autoLockMinutes' => $settings->autoLockMinutes(),
            'notifications' => $settings->notificationsEnabled(),
        ]);
    }

    public function save(AppSettings $settings): void
    {
        $validated = $this->validate([
            'locale' => ['required', Rule::in(['nl', 'en'])],
            'theme' => ['required', Rule::enum(ThemePreference::class)],
            'sitesDirectory' => ['required', 'string', 'max:500', 'starts_with:/,~'],
            'scriptPath' => ['required', 'string', 'max:500', 'starts_with:/,~'],
            'editor' => ['required', 'string', 'max:200', 'regex:/^[\w.\/~ -]+$/'],
            'terminal' => ['required', Rule::enum(TerminalApp::class)],
            'sshKeyPath' => ['required', 'string', 'max:500', 'starts_with:/,~'],
            'autoLoginLocal' => ['boolean'],
            'autoLoginLive' => ['boolean'],
            'autoLockMinutes' => ['required', 'integer', 'between:0,480'],
            'notifications' => ['boolean'],
        ], [
            'editor.regex' => __('Alleen de naam of het pad van het programma, zonder speciale tekens.'),
        ]);

        $localeChanged = $validated['locale'] !== $settings->locale();

        $settings->update([
            'locale' => $validated['locale'],
            'theme' => $validated['theme'],
            'sites_directory' => $validated['sitesDirectory'],
            'script_path' => $validated['scriptPath'],
            'editor' => $validated['editor'],
            'terminal' => $validated['terminal'],
            'ssh_key_path' => $validated['sshKeyPath'],
            'auto_login_local' => $validated['autoLoginLocal'],
            'auto_login_live' => $validated['autoLoginLive'],
            'auto_lock_minutes' => $validated['autoLockMinutes'],
            'notifications' => $validated['notifications'],
        ]);

        if ($localeChanged) {
            session()->flash('toast', ['title' => __('Instellingen opgeslagen'), 'tone' => 'success']);
            $this->redirectRoute('settings.index', navigate: false);

            return;
        }

        $this->dispatch('theme-changed', theme: $validated['theme']);
        $this->dispatch('toast', title: __('Instellingen opgeslagen'), tone: 'success');
    }

    public function changePassword(Vault $vault): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'confirmed', 'different:currentPassword', Password::min(10)],
        ], attributes: [
            'currentPassword' => __('huidig wachtwoord'),
            'newPassword' => __('nieuw wachtwoord'),
        ]);

        $user = auth()->user();

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('Dit is niet je huidige wachtwoord.'));

            return;
        }

        $vault->changePassword($user, $this->currentPassword, $this->newPassword);
        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');

        $this->dispatch('toast', title: __('Wachtwoord gewijzigd'), message: __('Je kluis is opnieuw versleuteld met het nieuwe wachtwoord.'), tone: 'success');
    }

    /**
     * Leest het script opnieuw in: nieuwere meegeleverde versie installeren en de sitelijst verversen.
     */
    public function reloadScript(ScriptInstaller $installer, SiteRegistry $registry): void
    {
        try {
            $installed = $installer->installIfOutdated();
            $count = $registry->sync();
        } catch (WpOpenException|ProcessFailedException $exception) {
            $this->dispatch('toast', title: __('wpopen kon niet geladen worden'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->dispatch('sites-changed');
        $this->dispatch('toast', title: $installed ? __('wpopen bijgewerkt en geladen') : __('wpopen opnieuw geladen'), message: trans_choice(':count site ingelezen|:count sites ingelezen', $count), tone: 'success');
    }

    public function reinstallScript(ScriptInstaller $installer): void
    {
        try {
            $installer->install();
        } catch (ProcessFailedException $exception) {
            $this->dispatch('toast', title: __('Installeren mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->dispatch('toast', title: __('wpopen geïnstalleerd'), message: '~/.local/bin/wpopen', tone: 'success');
    }

    public function runChecks(WpOpen $wpopen): void
    {
        try {
            $this->checks = (array) $wpopen->json(['setup', '--json'], 30);
        } catch (WpOpenException $exception) {
            $this->dispatch('toast', title: __('Systeemcontrole mislukt'), message: $exception->getMessage(), tone: 'danger');
        }
    }

    public function render(ScriptInstaller $installer): View
    {
        return view('livewire.settings.index', [
            'terminals' => TerminalApp::cases(),
            'themes' => ThemePreference::cases(),
            'installedVersion' => $installer->installedVersion(),
            'bundledVersion' => $installer->bundledVersion(),
        ]);
    }
}
