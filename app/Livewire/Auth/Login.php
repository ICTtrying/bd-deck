<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Vault;
use App\Services\WpOpen\SiteRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Inloggen')]
class Login extends Component
{
    public string $password = '';

    public function login(Vault $vault, SiteRegistry $registry): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $key = 'login|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => __('Te veel pogingen. Probeer het over :seconds seconden opnieuw.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::owner();

        if ($user === null || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $this->reset('password');

            throw ValidationException::withMessages(['password' => __('Dit wachtwoord klopt niet.')]);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        session()->regenerate();

        // een eigenaar uit een oudere versie heeft nog geen kluis: die maken we bij het inloggen aan
        if ($user->vault_key === null) {
            $vault->initialize($user, $this->password);
        } else {
            $vault->unlock($user, $this->password);
        }

        rescue(fn (): int => $registry->sync(), report: false);

        $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
