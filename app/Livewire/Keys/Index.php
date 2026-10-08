<?php

namespace App\Livewire\Keys;

use App\Services\AppSettings;
use App\Services\SshKeys;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('SSH-sleutels')]
class Index extends Component
{
    public string $name = 'id_ed25519_bddeck';

    public string $comment = '';

    public string $passphrase = '';

    public ?string $generated = null;

    protected SshKeys $keys;

    public function boot(SshKeys $keys): void
    {
        $this->keys = $keys;
    }

    public function mount(): void
    {
        $this->comment = get_current_user().'@'.gethostname();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function all(): array
    {
        return $this->keys->all();
    }

    public function generate(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'comment' => ['nullable', 'string', 'max:120'],
            'passphrase' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $key = $this->keys->generate($this->name, $this->comment, $this->passphrase ?: null);
        } catch (InvalidArgumentException|ProcessFailedException $exception) {
            $this->addError('name', $exception->getMessage());

            return;
        }

        $this->generated = $key['name'];
        $this->reset('passphrase');
        unset($this->all);
        $this->dispatch('close-modal', name: 'generate-key');
        $this->dispatch('toast', title: __('Sleutel aangemaakt'), message: $key['name'].'.pub', tone: 'success');
    }

    public function makeDefault(string $name, AppSettings $settings): void
    {
        $key = collect($this->all)->firstWhere('name', $name);

        if ($key === null || ! $key['has_private_key']) {
            return;
        }

        $settings->update(['ssh_key_path' => $this->keys->directory().'/'.$name]);
        unset($this->all);
        $this->dispatch('toast', title: __('Standaardsleutel ingesteld'), message: $name, tone: 'success');
    }

    public function render(): View
    {
        return view('livewire.keys.index');
    }
}
