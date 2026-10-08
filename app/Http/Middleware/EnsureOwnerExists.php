<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bij de eerste start bestaat er nog geen hoofdwachtwoord: dan eerst de welkomstpagina.
 */
class EnsureOwnerExists
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! User::query()->exists()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
