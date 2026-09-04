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
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/email_verification_deferral.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/account.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/identity.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_v1.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/event_pass_transfer.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/organization_experience.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/acquisition.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/workforce.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/commerce_fulfillment.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/leasing.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/leasing_context.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/documents.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/branding.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/content.php'));
            Route::prefix('api')->group(base_path('routes/compatibility.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/finance.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/ecosystem.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/cognition.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/market_data.php'));
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
