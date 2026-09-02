<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/account.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_v1.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/finance.php'));

            // Product-prefixed route files are compatibility adapters only.
            // They bind an application context; reusable business logic must live
            // in Domain/* and consume ApplicationScope instead of product names.
            Route::middleware(['api', 'app.bind:rasoio'])
                ->prefix('api')
                ->group(base_path('routes/rasoio.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/ecosystem.php'));

            Route::middleware(['api', 'app.bind:nexus'])
                ->prefix('api')
                ->group(base_path('routes/nexus.php'));

            Route::middleware(['api', 'app.bind:payflow'])
                ->prefix('api')
                ->group(base_path('routes/payflow.php'));

            Route::middleware(['api', 'app.bind:cutinapp'])
                ->prefix('api')
                ->group(base_path('routes/cutinapp.php'));

            Route::middleware(['api', 'app.bind:cutinapp'])
                ->prefix('api')
                ->group(base_path('routes/cutinapp_history.php'));

            Route::middleware(['api', 'app.bind:laora'])
                ->prefix('api')
                ->group(base_path('routes/laora.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
