<?php

namespace App\Livewire\Sites;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Livewire\Concerns\InteractsWithSites;
use App\Livewire\Forms\SiteForm;
use App\Models\Site;
use App\Services\WpOpen\SiteRegistry;
use App\Services\WpOpen\WpOpen;
use Illuminate\View\View;
use Livewire\Component;

class Edit extends Component
{
    use InteractsWithSites;

    public Site $site;

    public SiteForm $form;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->form->setSite($site);
    }

    public function save(WpOpen $wpopen, SiteRegistry $registry): void
    {
        if ($this->site->isLocalOnly()) {
            return;
        }

        $this->form->validate();

        $arguments = [
            'update', $this->site->name,
            '--name', $this->form->name,
            '--target', $this->form->target(),
            '--opts', $this->form->sshOptions(),
            '--remote', $this->form->remotePath,
            '--mode', $this->form->mode,
            '--provider', $this->form->provider,
            '--url', $this->form->liveUrl,
        ];

        $this->attempt(function () use ($wpopen, $registry, $arguments): void {
            $wpopen->runOrFail($arguments, 30);

            // eerst de rij hernoemen, anders ziet de sync een nieuwe site en raken favoriet en notities kwijt
            $this->site->update(['name' => $this->form->name, 'live_login_user' => $this->form->liveLoginUser ?: null]);
            $registry->refresh($this->site);

            session()->flash('toast', ['title' => __('Opgeslagen'), 'message' => $this->site->name, 'tone' => 'success']);
            $this->redirectRoute('sites.show', $this->site, navigate: true);
        });
    }

    public function render(): View
    {
        return view('livewire.sites.edit', [
            'providers' => SiteProvider::cases(),
            'modes' => SiteMode::remote(),
        ])->title(__(':site bewerken', ['site' => $this->site->name]));
    }
}
