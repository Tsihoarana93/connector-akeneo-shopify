<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceHttps($this->app->isProduction());

        $this->disableDebugModeInProduction();
    }

    /**
     * Debug pages show stack traces, queries and environment values, so production never serves them,
     * even when APP_DEBUG was left on by mistake. The warning is logged at most once an hour, not on every request.
     */
    private function disableDebugModeInProduction(): void
    {
        if (! $this->app->isProduction() || ! config('app.debug')) {
            return;
        }

        config(['app.debug' => false]);

        if (Cache::add('app-debug-in-production-warned', true, now()->addHour())) {
            Log::warning('APP_DEBUG is true in production, so debug mode was turned off. Set APP_DEBUG=false in .env.');
        }
    }
}
