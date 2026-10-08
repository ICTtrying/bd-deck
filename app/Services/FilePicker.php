<?php

namespace App\Services;

use Native\Desktop\Dialog;

/**
 * De bestandskiezer van het desktopvenster; los van de componenten, zodat tests hem kunnen vervangen.
 */
class FilePicker
{
    /**
     * @return list<string>
     */
    public function zips(string $title): array
    {
        return array_values((array) Dialog::new()
            ->title($title)
            ->filter(__('Zip-bestanden'), ['zip'])
            ->multiple()
            ->open());
    }
}
