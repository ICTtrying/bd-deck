<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\DesktopLauncher;
use App\Services\WpOpen\WpOpen;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CommandPalette extends Component
{
    use InteractsWithSites;

    public string $query = '';

    /**
     * @return list<array{type: string, label: string, hint: string, icon: string, url?: string, site?: int, target?: string}>
     */
    #[Computed]
    public function results(): array
    {
        $sites = Site::query()
            ->when($this->query !== '', fn ($query) => $query->search($this->query))
            ->ordered()
            ->limit(6)
            ->get();

        $items = [];

        foreach ($sites as $site) {
            $items[] = ['type' => 'url', 'label' => $site->name, 'hint' => (string) $site->data('live_host'), 'icon' => 'globe', 'url' => route('sites.show', $site)];
        }

        // snelacties alleen voor de beste treffer: dat is bijna altijd de site die je zoekt
        if ($this->query !== '' && ($first = $sites->first())) {
            if ($first->isBuilt() && ! $first->isLaravel()) {
                $items[] = ['type' => 'launch', 'label' => __('WP-admin lokaal'), 'hint' => $first->name, 'icon' => 'wordpress', 'site' => $first->id, 'target' => 'local-admin'];
            }
            if ($first->isBuilt()) {
                $items[] = ['type' => 'launch', 'label' => __('Openen in editor'), 'hint' => $first->name, 'icon' => 'code', 'site' => $first->id, 'target' => 'editor'];
            }
            if (! $first->isLaravel()) {
                $items[] = ['type' => 'launch', 'label' => __('WP-admin live'), 'hint' => $first->name, 'icon' => 'external', 'site' => $first->id, 'target' => 'live-admin'];
            }
            $items[] = ['type' => 'launch', 'label' => __('Live website openen'), 'hint' => $first->name, 'icon' => 'globe', 'site' => $first->id, 'target' => 'live-site'];
            $items[] = ['type' => 'launch', 'label' => __('SSH-sessie'), 'hint' => $first->name, 'icon' => 'server', 'site' => $first->id, 'target' => 'ssh'];
        }

        $pages = [
            ['label' => __('Site toevoegen'), 'icon' => 'plus', 'url' => route('sites.create')],
            ['label' => __('Activiteit'), 'icon' => 'history', 'url' => route('activity.index')],
            ['label' => __('SSH-sleutels'), 'icon' => 'key', 'url' => route('keys.index')],
            ['label' => __('Kluis'), 'icon' => 'shield', 'url' => route('vault.index')],
            ['label' => __('Instellingen'), 'icon' => 'settings', 'url' => route('settings.index')],
        ];

        foreach ($pages as $page) {
            if ($this->query === '' || str_contains(mb_strtolower($page['label']), mb_strtolower($this->query))) {
                $items[] = ['type' => 'url', 'hint' => __('Pagina'), ...$page];
            }
        }

        return $items;
    }

    public function choose(int $index, DesktopLauncher $launcher, AppSettings $settings, WpOpen $wpopen): void
    {
        $item = $this->results[$index] ?? null;

        if ($item === null) {
            return;
        }

        $this->reset('query');
        $this->dispatch('close-palette');

        if ($item['type'] === 'url') {
            $this->redirect($item['url'], navigate: true);

            return;
        }

        $this->launch($item['site'], $item['target'], $launcher, $settings, $wpopen);
    }

    public function render(): View
    {
        return view('livewire.command-palette');
    }
}
