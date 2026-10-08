<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Vault;
use App\Services\WpOpen\SiteRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Welkom')]
class Setup extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('login');
        }
    }

    public function save(Vault $vault, SiteRegistry $registry): void
    {
        $this->validate([
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        // twee keer snel klikken mag nooit twee eigenaren opleveren
        $user = DB::transaction(function (): ?User {
            if (User::query()->lockForUpdate()->exists()) {
                return null;
            }

            return User::query()->create([
                'name' => 'Eigenaar',
                'email' => 'eigenaar@bd-deck.local',
                'password' => $this->password,
            ]);
        });

        if ($user === null) {
            $this->redirectRoute('login');

            return;
        }

        Auth::login($user);
        session()->regenerate();
        $vault->initialize($user, $this->password);

        rescue(fn (): int => $registry->sync(), report: false);

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.setup');
    }
}
