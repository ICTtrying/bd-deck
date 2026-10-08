<?php

namespace App\Http\Controllers;

use App\Services\Vault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LockController extends Controller
{
    public function __invoke(Request $request, Vault $vault): RedirectResponse
    {
        $vault->lock();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
