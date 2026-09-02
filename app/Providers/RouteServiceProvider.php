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
            $legacy = ['api', 'legacy.deprecated'];

            Route::middleware($legacy)->prefix('api')->group(base_path('routes/api.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/account.php'));

            // Stable Peter Platform contract. Never inherits legacy deprecation headers.
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_v1.php'));

            Route::middleware($legacy)->prefix('api')->group(base_path('routes/rasoio.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/ecosystem.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/nexus.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/payflow.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/cutinapp.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/cutinapp_history.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/laora.php'));

            Route::middleware('web')->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
