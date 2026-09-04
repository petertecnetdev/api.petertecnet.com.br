<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

final class AssetRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Route::middleware('api')
            ->group(base_path('routes/assets.php'));
    }
}
