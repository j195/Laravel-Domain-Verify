<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
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
        // Older MySQL/InnoDB on WAMP only allows 1000-byte indexes; utf8mb4 255-char unique keys exceed that.
        Schema::defaultStringLength(191);
    }
}
