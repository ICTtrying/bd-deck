<?php

namespace App\Listeners;

use App\Support\MainWindow;
use Illuminate\Support\Str;
use Native\Desktop\Events\Menu\MenuItemClicked;

/**
 * Klikken in het tray-menu: daar is geen actief venster, dus we openen of tonen het hoofdvenster zelf.
 */
class HandleNativeMenuClick
{
    public function handle(MenuItemClicked $event): void
    {
        $id = (string) ($event->item['id'] ?? '');

        $url = match (true) {
            $id === 'open' => null,
            $id === 'new-site' => route('sites.create'),
            $id === 'lock' => route('lock'),
            Str::startsWith($id, 'site:') => route('sites.show', Str::after($id, 'site:')),
            default => false,
        };

        if ($url !== false) {
            MainWindow::show($url);
        }
    }
}
