<?php

namespace App\Livewire\Sites;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Veilig live zetten van een Shopify-thema: ophalen wat de klant deed, controleren, naar een niet-gepubliceerd
 * thema pushen, de preview bekijken en pas dan bewust publiceren (met een weg terug).
 */
class ShopifyDeploy extends Component
{
    use InteractsWithSites;

    public Site $site;

    /**
     * Naam van het preview-thema dat bijgewerkt of aangemaakt wordt.
     */
    public string $pushName = '';

    public bool $pushPull = true;

    public bool $pushCheck = true;

    public ?int $publishThemeId = null;

    public bool $publishSyncEditorFiles = true;

    public string $confirmName = '';

    #[On('sites-changed')]
    public function refreshSite(): void
    {
        $this->site->refresh();
        unset($this->checkBlockedRun, $this->previewThemes, $this->publishTheme, $this->liveTheme);
    }

    /**
     * Bestaande preview-thema's: daar kun je naartoe pushen zonder een nieuw thema aan te maken.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function previewThemes(): Collection
    {
        return collect($this->site->shopifyThemes())->where('role', 'unpublished')->values();
    }

    /**
     * De laatste push die door de themacontrole is tegengehouden: dan kun je bewust toch doorgaan.
     */
    #[Computed]
    public function checkBlockedRun(): ?CommandRun
    {
        $run = $this->site->runs()->where('action', WpOpenAction::ShopifyPush)->latestFirst()->first();

        return $run?->status === RunStatus::Failed && str_contains((string) $run->output, '--allow-check-errors') ? $run : null;
    }

    public function openPush(): void
    {
        $this->resetValidation();
        $this->pushName = $this->site->lastPush()['theme_name'] ?? '';
        $this->dispatch('open-modal', name: 'shopify-push');
    }

    public function push(CommandRunner $runner): void
    {
        $this->validate(['pushName' => ['nullable', 'string', 'max:60', 'not_regex:/[|\n\r]/']], [], ['pushName' => __('themanaam')]);

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::shopifyPush($this->site, $this->pushName, pullFirst: $this->pushPull, check: $this->pushCheck));
            $this->dispatch('close-modal', name: 'shopify-push');
        });
    }

    /**
     * Na een geblokkeerde push: dezelfde push opnieuw, met de bestaande fouten geaccepteerd.
     */
    public function pushAcceptingErrors(CommandRunner $runner): void
    {
        $blocked = $this->checkBlockedRun;

        if ($blocked === null) {
            return;
        }

        $arguments = $blocked->arguments;
        $name = $arguments[array_search('--theme', $arguments, true) + 1] ?? null;

        $this->attempt(function () use ($runner, $name): void {
            $this->queueCommand($runner, WpOpenCommand::shopifyPush($this->site, is_string($name) ? $name : null, allowCheckErrors: true));
        });
    }

    public function pullFiles(bool $all, CommandRunner $runner): void
    {
        $this->attempt(function () use ($all, $runner): void {
            $this->queueCommand($runner, WpOpenCommand::shopifyPull($this->site, $all));
            $this->dispatch('close-modal', name: 'shopify-pull-all');
        });
    }

    public function openPublish(int $themeId): void
    {
        $theme = collect($this->site->shopifyThemes())->firstWhere('id', $themeId);

        if ($theme === null || $theme['role'] !== 'unpublished') {
            $this->dispatch('toast', title: __('Dat lukte niet'), message: __('Alleen een niet-gepubliceerd thema kan live gezet worden. Haal de themalijst opnieuw op.'), tone: 'danger');

            return;
        }

        $this->publishThemeId = $themeId;
        $this->publishSyncEditorFiles = true;
        $this->confirmName = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'shopify-publish');
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function publishTheme(): ?array
    {
        return collect($this->site->shopifyThemes())->firstWhere('id', $this->publishThemeId);
    }

    /**
     * Het thema dat nu live staat volgens de laatst opgehaalde lijst.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function liveTheme(): ?array
    {
        return collect($this->site->shopifyThemes())->firstWhere('role', 'live');
    }

    public function publish(CommandRunner $runner): void
    {
        $this->resetValidation();

        if ($this->publishTheme === null) {
            return;
        }

        if ($this->confirmName !== $this->site->name) {
            $this->addError('confirmName', __('Typ de naam van het project om te bevestigen.'));

            return;
        }

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::shopifyPublish($this->site, (int) $this->publishThemeId, $this->publishSyncEditorFiles));
            $this->dispatch('close-modal', name: 'shopify-publish');
            $this->reset('publishThemeId', 'confirmName');
        });
    }

    public function rollback(CommandRunner $runner): void
    {
        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::shopifyRollback($this->site));
            $this->dispatch('close-modal', name: 'shopify-rollback');
        });
    }

    public function render(): View
    {
        return view('livewire.sites.shopify-deploy');
    }
}
