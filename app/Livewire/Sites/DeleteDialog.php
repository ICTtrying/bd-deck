<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Eén verwijdervenster voor lijst, sitepagina en gegevenspagina.
 */
class DeleteDialog extends Component
{
    use InteractsWithSites;

    public ?int $siteId = null;

    public bool $purgeLocal = false;

    #[On('confirm-delete-site')]
    public function ask(int $siteId): void
    {
        $this->siteId = Site::query()->whereKey($siteId)->value('id');
        $this->reset('purgeLocal');
        unset($this->site);

        if ($this->siteId !== null) {
            $this->dispatch('open-modal', name: 'delete-site');
        }
    }

    #[Computed]
    public function site(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    public function delete(CommandRunner $runner): void
    {
        $site = $this->site;

        if ($site === null) {
            return;
        }

        // een lokale site zonder server bestaat alleen lokaal: weghalen betekent dan ook de map weggooien
        $purge = $this->purgeLocal || $site->isLocalOnly();
        $this->queueCommand($runner, WpOpenCommand::removeSite($site, $purge));

        // meteen uit de lijst: wpopen haalt hem in de wachtrij weg, en mislukt dat dan zet de volgende sync hem terug
        $site->delete();
        $this->dispatch('sites-changed');

        $this->dispatch('close-modal', name: 'delete-site');
        session()->flash('toast', ['title' => __('Site wordt verwijderd'), 'message' => $site->name, 'tone' => 'info']);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sites.delete-dialog');
    }
}
