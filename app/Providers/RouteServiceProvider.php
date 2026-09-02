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

            // Canonical shared platform contract.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_v1.php'));

            // Temporary expand/contract boundary. The file owns its middleware
            // groups so each legacy URL can bind the appropriate application
            // context without duplicating the global API middleware stack.
            Route::prefix('api')
                ->group(base_path('routes/compatibility.php'));

            // Global provider callbacks only. User-facing finance operations are
            // application-scoped under /api/v1/apps/{application}/... .
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/finance.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/ecosystem.php'));

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
