<?php

namespace App\Support;

use Native\Desktop\Facades\Window;
use Throwable;

/**
 * Het ene hoofdvenster: openen als het er niet is, anders tonen en naar de juiste pagina gaan.
 */
final class MainWindow
{
    public const string ID = 'main';

    public static function open(?string $url = null): void
    {
        Window::open(self::ID)
            ->title('BD Deck')
            ->url($url ?? route('dashboard'))
            ->width(1320)
            ->height(860)
            ->minWidth(980)
            ->minHeight(640)
            ->backgroundColor('#f3f6f9')
            ->rememberState();
    }

    public static function show(?string $url = null): void
    {
        try {
            $window = Window::get(self::ID);

            if ($url !== null) {
                $window->url($url);
            }

            Window::show(self::ID);
        } catch (Throwable) {
            self::open($url);
        }
    }
}
