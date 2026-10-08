<?php

namespace App\Livewire\Vault;

use App\Enums\CredentialKind;
use App\Exceptions\VaultLockedException;
use App\Models\Credential;
use App\Models\Site;
use App\Services\Vault;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Kluis')]
class Index extends Component
{
    #[Url(as: 'zoek', except: '')]
    public string $search = '';

    public ?int $editingId = null;

    public string $label = '';

    public string $kind = 'other';

    public ?int $siteId = null;

    public string $username = '';

    public string $secret = '';

    public string $url = '';

    public string $notes = '';

    /**
     * Ontsleutelde waarde die tijdelijk zichtbaar is; nooit opgeslagen in de component-state na verbergen.
     */
    public ?int $revealedId = null;

    public ?string $revealedSecret = null;

    /**
     * @return Collection<int, Credential>
     */
    #[Computed]
    public function credentials(): Collection
    {
        return Credential::query()
            ->with('site:id,name')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('label', 'like', '%'.$this->search.'%')
                ->orWhere('username', 'like', '%'.$this->search.'%')
                ->orWhere('url', 'like', '%'.$this->search.'%')))
            ->orderBy('label')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function sites(): Collection
    {
        return Site::query()->orderBy('name')->get(['id', 'name']);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', 'credential');
    }

    public function edit(int $id): void
    {
        $credential = Credential::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $credential->id;
        $this->fill([
            'label' => $credential->label,
            'kind' => $credential->kind->value,
            'siteId' => $credential->site_id,
            'username' => (string) $credential->username,
            'url' => (string) $credential->url,
            'notes' => (string) $credential->notes,
        ]);
        $this->dispatch('open-modal', 'credential');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(CredentialKind::class)],
            'siteId' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => [Rule::requiredIf($this->editingId === null), 'nullable', 'string', 'max:10000'],
            'url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $attributes = [
            'label' => $validated['label'],
            'kind' => $validated['kind'],
            'site_id' => $validated['siteId'],
            'username' => $validated['username'] ?: null,
            'url' => $validated['url'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ];

        // leeg geheim bij bewerken = ongewijzigd laten
        if ($this->secret !== '') {
            $attributes['secret'] = $this->secret;
        }

        try {
            Credential::query()->updateOrCreate(['id' => $this->editingId], $attributes);
        } catch (VaultLockedException $exception) {
            $this->addError('secret', $exception->getMessage());

            return;
        }

        $this->resetForm();
        unset($this->credentials);
        $this->dispatch('close-modal', 'credential');
        $this->dispatch('toast', title: __('Opgeslagen in de kluis'), tone: 'success');
    }

    public function reveal(int $id, Vault $vault): void
    {
        if ($this->revealedId === $id) {
            $this->hide();

            return;
        }

        try {
            $this->revealedSecret = Credential::query()->findOrFail($id)->secret?->reveal($vault);
            $this->revealedId = $id;
        } catch (VaultLockedException $exception) {
            $this->dispatch('toast', title: __('Kluis vergrendeld'), message: $exception->getMessage(), tone: 'danger');
        }
    }

    public function hide(): void
    {
        $this->revealedId = null;
        $this->revealedSecret = null;
    }

    public function delete(int $id): void
    {
        Credential::query()->whereKey($id)->delete();
        $this->hide();
        unset($this->credentials);
        $this->dispatch('toast', title: __('Verwijderd uit de kluis'), tone: 'success');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'label', 'kind', 'siteId', 'username', 'secret', 'url', 'notes');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.vault.index', ['kinds' => CredentialKind::cases()]);
    }
}
