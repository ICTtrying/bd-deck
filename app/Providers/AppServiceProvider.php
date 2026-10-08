<?php

namespace App\Providers;

use App\Services\AppSettings;
use App\View\Composers\LayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
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

        // korte acties staan op een eigen wachtrij; zonder 'quick' zouden test en updates blijven hangen
        DevCommands::artisan('queue:listen --queue=default,quick --tries=1 --timeout=0', 'queue');

        View::composer(['layouts::app', 'layouts::guest', 'layouts.app', 'layouts.guest'], LayoutComposer::class);

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by('login|'.$request->ip()));
    }
}
