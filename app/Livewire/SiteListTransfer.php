<?php

namespace App\Livewire;

use App\Exceptions\WpOpenException;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\FilePicker;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\SiteListExchange;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use InvalidArgumentException;
use JsonException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Sitelijst exporteren voor een collega en die van een collega importeren (dashboard en Aan de slag).
 */
class SiteListTransfer extends Component
{
    public bool $includeNotes = false;

    #[Locked]
    public ?string $importPath = null;

    /**
     * @var list<array{name: string, status: string, reason: ?string, target: ?string, notes: ?string}>
     */
    #[Locked]
    public array $importRows = [];

    /**
     * Aangevinkte sites: nieuwe staan standaard aan, bestaande met andere gegevens niet.
     *
     * @var list<string>
     */
    public array $importSelected = [];

    /**
     * @var array{added: list<string>, updated: list<string>, skipped: list<string>, invalid: list<array{name: string, reason: string}>}|null
     */
    #[Locked]
    public ?array $importResult = null;

    #[On('open-site-export')]
    public function openExport(): void
    {
        $this->includeNotes = false;
        unset($this->exportCounts);
        $this->dispatch('open-modal', name: 'export-sites');
    }

    /**
     * @return array{shared: int, local: int, notes: int}
     */
    #[Computed]
    public function exportCounts(): array
    {
        $sites = Site::query()->get(['id', 'snapshot', 'notes']);
        $shared = $sites->reject->isLocalOnly();

        return [
            'shared' => $shared->count(),
            'local' => $sites->count() - $shared->count(),
            'notes' => $shared->filter(fn (Site $site): bool => filled($site->notes))->count(),
        ];
    }

    public function export(SiteListExchange $exchange, FilePicker $picker, AppSettings $settings): void
    {
        $path = $picker->saveJson(__('Sitelijst opslaan'), $settings->homeDirectory().'/bd-deck-sites-'.now()->format('Y-m-d').'.json');

        if ($path === null) {
            return;
        }

        try {
            $export = $exchange->export($this->includeNotes);
            File::put($path, $export['json']);
        } catch (WpOpenException|JsonException $exception) {
            $this->dispatch('toast', title: __('Exporteren mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->dispatch('close-modal', name: 'export-sites');
        $this->dispatch('toast', title: trans_choice('{0} Sitelijst opgeslagen (geen sites)|{1} Sitelijst opgeslagen (één site)|[2,*] Sitelijst opgeslagen (:count sites)', $export['count']), message: $path, tone: 'success');
    }

    #[On('start-site-import')]
    public function chooseImportFile(SiteListExchange $exchange, FilePicker $picker): void
    {
        $path = $picker->json(__('Sitelijst van een collega kiezen'));

        if ($path === null) {
            return;
        }

        try {
            $rows = $exchange->preview($path);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('toast', title: __('Dit bestand kan niet geïmporteerd worden'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        $this->importPath = $path;
        $this->importRows = $rows;
        $this->importSelected = collect($rows)->where('status', 'new')->pluck('name')->all();
        $this->importResult = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'import-sites');
    }

    public function import(SiteListExchange $exchange, CommandRunner $runner): void
    {
        // alleen wat in de voorvertoning echt aan te vinken was; geldige namen zijn uniek, dubbele staan als ongeldig
        $chosen = collect($this->importRows)->whereIn('status', ['new', 'different'])->keyBy('name')->only($this->importSelected);

        if ($this->importPath === null || $chosen->isEmpty()) {
            $this->addError('importSelected', __('Kies minstens één site om te importeren.'));

            return;
        }

        try {
            $summary = $exchange->import(
                $this->importPath,
                $chosen->keys()->all(),
                update: $chosen->contains('status', 'different'),
                notes: $chosen->pluck('notes', 'name')->filter()->all(),
            );
        } catch (WpOpenException|InvalidArgumentException $exception) {
            $this->dispatch('toast', title: __('Importeren mislukt'), message: $exception->getMessage(), tone: 'danger');

            return;
        }

        // meteen testen: dan zie je in de lijst welke sites nog SSH-toegang voor jouw sleutel nodig hebben
        $tests = Site::query()->whereIn('name', $summary['added'])->get()
            ->map(fn (Site $site): int => $runner->queue(WpOpenCommand::test($site), notify: false)->id);

        $this->importResult = $summary;
        $this->dispatch('sites-changed');

        if ($tests->isNotEmpty()) {
            $this->dispatch('run-started', runId: $tests->last());
        }
    }

    public function closeImport(): void
    {
        $this->reset('importPath', 'importRows', 'importSelected', 'importResult');
        $this->dispatch('close-modal', name: 'import-sites');
    }

    public function render(): View
    {
        return view('livewire.site-list-transfer');
    }
}
