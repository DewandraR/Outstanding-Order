<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL; // <-- [1] TAMBAHKAN BARIS INI

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // [2] TAMBAHKAN BLOK KODE INI
        if (config('app.env') === 'production' || config('app.env') === 'staging') {
            URL::forceScheme('http');
        }
    }
}
