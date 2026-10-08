<?php

namespace App\View\Composers;

use App\Services\AppSettings;
use App\Services\WpOpen\ScriptInstaller;
use Illuminate\View\View;

class LayoutComposer
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly ScriptInstaller $installer,
    ) {}

    public function compose(View $view): void
    {
        // bij de allereerste start bestaat de settings-tabel nog niet als migraties nog lopen
        $view->with([
            'theme' => rescue(fn () => $this->settings->theme()->value, 'system', report: false),
            'autoLockMinutes' => auth()->check() ? rescue(fn (): int => $this->settings->autoLockMinutes(), 0, report: false) : 0,
            'scriptVersion' => rescue(fn (): ?string => $this->installer->installedVersion(), null, report: false),
        ]);
    }
}
