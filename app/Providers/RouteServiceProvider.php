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

            // Generic identity contract. New authentication capabilities live here
            // while legacy /auth routes remain available during the migration.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/identity.php'));

            // Production Peter Identity SDK aliases are loaded after the core so
            // stronger policies can replace compatibility routes without duplicating
            // business logic in each frontend application.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/identity_experience.php'));

            // Canonical shared platform contract.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_v1.php'));

            // Application branding is a shared platform capability. Keeping it in a
            // dedicated contract avoids app-specific controllers and lets every UI
            // migrate from hard-coded assets without changing legacy endpoints.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/branding.php'));

            // Content, SEO discovery and acquisition analytics are ecosystem-wide
            // capabilities. Applications opt into context instead of owning routes.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/content.php'));

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
