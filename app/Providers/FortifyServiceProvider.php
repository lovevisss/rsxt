<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Authentication is handled exclusively by the information portal.
        Fortify::ignoreRoutes();
    }
}
