<?php

namespace App\Livewire\Settings;

use App\Services\FilePicker;
use App\Services\WpOpen\PremiumLibrary;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

class PremiumFiles extends Component
{
    public function choose(PremiumLibrary $library, FilePicker $picker): void
    {
        try {
            $paths = $picker->zips(__('Kies de zips van Enfold en de WPMU DEV-plugins'));
        } catch (Throwable) {
            $this->dispatch('toast', title: __('Bestanden kiezen kan alleen in de desktop-app'), message: __('Zet de zips zelf in :map.', ['map' => $library->directory()]), tone: 'danger');

            return;
        }

        if (blank($paths)) {
            return;
        }

        $result = $library->import($paths);

        if ($result['added'] > 0) {
            $this->dispatch('toast', title: trans_choice(':count zip toegevoegd|:count zips toegevoegd', $result['added']), tone: 'success');
        }

        if ($result['rejected'] !== []) {
            $this->dispatch('toast', title: __('Overgeslagen: geen thema of plugin'), message: implode(', ', $result['rejected']), tone: 'danger');
        }
    }

    public function remove(string $file, PremiumLibrary $library): void
    {
        $library->delete($file);
    }

    public function render(PremiumLibrary $library): View
    {
        return view('livewire.settings.premium-files', [
            'items' => $library->items(),
            'directory' => $library->directory(),
        ]);
    }
}
