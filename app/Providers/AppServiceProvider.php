<?php

namespace App\Providers;

use App\Services\AppSettings;
use App\View\Composers\LayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped: een queue-worker leeft lang en moet gewijzigde instellingen per job zien
        $this->app->scoped(AppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        View::composer(['layouts::app', 'layouts::guest', 'layouts.app', 'layouts.guest'], LayoutComposer::class);

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by('login|'.$request->ip()));
    }
}
