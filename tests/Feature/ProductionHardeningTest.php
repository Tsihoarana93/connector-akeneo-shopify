<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    public function test_production_turns_debug_mode_off_and_generates_https_urls(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        Log::shouldReceive('warning')->once()->with('APP_DEBUG is true in production, so debug mode was turned off. Set APP_DEBUG=false in .env.');

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
        $this->assertStringStartsWith('https://', url('/sync-runs'));
    }

    public function test_the_production_debug_warning_is_logged_once_an_hour(): void
    {
        $this->app['env'] = 'production';
        Log::shouldReceive('warning')->twice();

        foreach (range(1, 3) as $request) {
            config(['app.debug' => true]);
            (new AppServiceProvider($this->app))->boot();
        }

        $this->travel(61)->minutes();
        config(['app.debug' => true]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_other_environments_keep_debug_mode_and_their_url_scheme(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://localhost']);
        Log::shouldReceive('warning')->never();

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('app.debug'));
        $this->assertStringStartsWith('http://', url('/sync-runs'));
    }
}
