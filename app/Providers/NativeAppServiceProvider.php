<?php

namespace App\Providers;

use App\Models\Site;
use App\Services\WpOpen\ScriptInstaller;
use App\Services\WpOpen\SiteRegistry;
use App\Support\MainWindow;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function __construct(
        private readonly ScriptInstaller $installer,
        private readonly SiteRegistry $registry,
    ) {}

    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // terminal en app moeten hetzelfde script draaien; een nieuwere app levert een nieuwere wpopen
        rescue(fn (): bool => $this->installer->installIfOutdated(), report: false);
        rescue(fn (): int => $this->registry->sync(), report: false);

        $this->registerMenu();
        $this->registerTray();

        MainWindow::open();
    }

    private function registerMenu(): void
    {
        Menu::create(
            Menu::make(
                Menu::route('dashboard', __('Sites'), 'CmdOrCtrl+1'),
                Menu::route('activity.index', __('Activiteit'), 'CmdOrCtrl+2'),
                Menu::route('keys.index', __('SSH-sleutels'), 'CmdOrCtrl+3'),
                Menu::route('vault.index', __('Kluis'), 'CmdOrCtrl+4'),
                Menu::separator(),
                Menu::route('settings.index', __('Instellingen'), 'CmdOrCtrl+,'),
                Menu::route('lock', __('Vergrendelen'), 'CmdOrCtrl+L'),
                Menu::separator(),
                Menu::quit(__('Afsluiten')),
            )->label('BD Deck'),
            Menu::make(
                Menu::route('sites.create', __('Site toevoegen'), 'CmdOrCtrl+N'),
                Menu::route('dashboard', __('Alle sites')),
            )->label(__('Sites')),
            Menu::edit(__('Bewerken')),
            Menu::make(
                Menu::reload(__('Herladen')),
                Menu::fullscreen(__('Volledig scherm')),
                ...(config('app.debug') ? [Menu::devTools(__('Ontwikkelaarstools'))] : []),
            )->label(__('Beeld')),
            Menu::make(
                Menu::link('https://borgmandigital.nl', 'borgmandigital.nl')->openInBrowser(),
                Menu::link('https://wpmudev.com/hub2/', 'WPMU DEV Hub')->openInBrowser(),
                Menu::link('https://hpanel.hostinger.com/', 'Hostinger hPanel')->openInBrowser(),
                Menu::link('https://ddev.readthedocs.io/', __('DDEV-documentatie'))->openInBrowser(),
            )->label(__('Help')),
        );
    }

    private function registerTray(): void
    {
        $favorites = rescue(fn () => Site::query()->favorite()->ordered()->limit(8)->pluck('name'), collect(), report: false);

        $items = [Menu::label(__('BD Deck openen'))->id('open')];

        if ($favorites->isNotEmpty()) {
            $items[] = Menu::separator();

            foreach ($favorites as $name) {
                $items[] = Menu::label($name)->id('site:'.$name);
            }
        }

        array_push(
            $items,
            Menu::separator(),
            Menu::label(__('Site toevoegen'))->id('new-site'),
            Menu::label(__('Vergrendelen'))->id('lock'),
            Menu::separator(),
            Menu::quit(__('Afsluiten')),
        );

        MenuBar::create()
            ->icon(resource_path('images/menuBarIcon.png'))
            ->tooltip('BD Deck')
            ->showDockIcon()
            ->onlyShowContextMenu()
            ->withContextMenu(Menu::make(...$items));
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            // lange syncs lopen in de queue; dit voorkomt dat een trage SSH-aanroep in een request afbreekt
            'max_execution_time' => '0',
        ];
    }
}
