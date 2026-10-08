<?php

use App\Http\Controllers\LockController;
use App\Livewire\Activity;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Setup;
use App\Livewire\Dashboard;
use App\Livewire\Keys;
use App\Livewire\Settings;
use App\Livewire\Sites;
use App\Livewire\Vault;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::livewire('/welkom', Setup::class)->name('setup');
    Route::livewire('/inloggen', Login::class)->middleware('owner')->name('login');
});

Route::middleware(['owner', 'auth'])->group(function (): void {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    // 'nieuw' vóór {site}, anders wordt het als sitenaam gelezen
    Route::livewire('/sites/nieuw', Sites\Create::class)->name('sites.create');
    Route::livewire('/sites/{site}', Sites\Show::class)->name('sites.show');
    Route::livewire('/sites/{site}/bewerken', Sites\Edit::class)->name('sites.edit');

    Route::livewire('/activiteit', Activity\Index::class)->name('activity.index');
    Route::livewire('/activiteit/{run}', Activity\Show::class)->name('activity.show');

    Route::livewire('/ssh-sleutels', Keys\Index::class)->name('keys.index');
    Route::livewire('/kluis', Vault\Index::class)->name('vault.index');
    Route::livewire('/instellingen', Settings\Index::class)->name('settings.index');

    Route::post('/vergrendelen', LockController::class)->name('lock');
});
