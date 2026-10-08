<?php

namespace App\Livewire\Concerns;

use App\Livewire\Forms\SiteForm;

/**
 * Een geplakte verbinding ("ssh -p 65002 u123@1.2.3.4") vult gebruiker, server, poort en hosting in,
 * ook als iemand hem in het gebruikers- of serverveld plakt.
 *
 * @property SiteForm $form
 * @property string $connection
 */
trait FillsConnectionFields
{
    public function updatedConnection(string $value): void
    {
        $this->form->fillFromConnectionString($value);
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['user', 'host'], true) && is_string($value) && str_contains($value, '@')) {
            $this->form->fillFromConnectionString($value);
        }
    }
}
