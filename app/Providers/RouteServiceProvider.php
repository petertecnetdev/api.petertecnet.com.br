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

            // Canonical shared platform contract.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_v1.php'));

            // Event lifecycle is a shared domain contract used by any application
            // with events/commerce capabilities. It intentionally contains no
            // application-specific controller or route namespace.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/event_lifecycle.php'));

            // Shared workforce contract. Read/delete operations live here while the
            // canonical create route remains in api_v1.php during migration.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/workforce.php'));

            // Generic fulfillment extends the canonical commerce contract without
            // adding application-specific controllers or legacy URL namespaces.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/commerce_fulfillment.php'));

            // Generic property, lease, document, signature and recurring billing
            // contract. Applications opt in through the leasing capability.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/leasing.php'));

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
