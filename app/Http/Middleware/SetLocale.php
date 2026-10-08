<?php

namespace App\Http\Middleware;

use App\Services\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = rescue(fn (): string => $this->settings->locale(), 'nl', report: false);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
