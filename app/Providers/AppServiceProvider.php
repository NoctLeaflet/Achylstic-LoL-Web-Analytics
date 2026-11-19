<?php

namespace App\Providers;

use App\Services\Stats\PlayerPalette;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One palette per request so every chart colours the same player the same way.
        $this->app->scoped(PlayerPalette::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
