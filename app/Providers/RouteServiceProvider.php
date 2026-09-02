<?php

namespace App\Providers;

use App\Infrastructure\Http\LegacyV1RouteRegistrar;
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

            // Every v1 request is metered, including Peter first-party JWT traffic.
            // External projects add project identity/quota middleware at route level.
            Route::middleware(['api', 'api.usage'])->prefix('api')->group(base_path('routes/api_v1.php'));

            Route::middleware($legacy)->prefix('api')->group(base_path('routes/rasoio.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/ecosystem.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/nexus.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/payflow.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/cutinapp.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/cutinapp_history.php'));
            Route::middleware($legacy)->prefix('api')->group(base_path('routes/laora.php'));

            // Product-specific legacy contracts are mirrored below /v1 as temporary
            // adapters. Canonical v1 routes registered above always take precedence.
            app(LegacyV1RouteRegistrar::class)->register([
                'cutinapp',
                'rasoio',
                'nexus',
                'plat',
                'payflow',
                'inkap',
                'laora',
            ]);

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
