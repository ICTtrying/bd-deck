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

    /**
     * @return string|null null als de gebruiker annuleert
     */
    public function json(string $title): ?string
    {
        $path = Dialog::new()
            ->title($title)
            ->filter(__('JSON-bestanden'), ['json'])
            ->open();

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @return string|null null als de gebruiker annuleert
     */
    public function saveJson(string $title, string $defaultPath): ?string
    {
        $path = Dialog::new()
            ->title($title)
            ->defaultPath($defaultPath)
            ->filter(__('JSON-bestanden'), ['json'])
            ->save();

        return is_string($path) && $path !== '' ? $path : null;
    }
}
