<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

            // Canonical shared platform contract used by Peter Tecnet applications.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_v1.php'));

            // Stable third-party developer contract. It deliberately does not use the
            // legacy `api` middleware group, so internal interaction telemetry and the
            // IP-only throttle cannot inspect developer credentials or cap API clients
            // before their per-client policy has been resolved.
            Route::middleware([SubstituteBindings::class])
                ->prefix('api')
                ->group(base_path('routes/developer.php'));

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

        RateLimiter::for('developer', function (Request $request) {
            $client = $request->attributes->get('developer_client');
            $limit = max(1, (int) ($client?->rate_limit_per_minute ?? config('developer.default_rate_limit_per_minute', 60)));
            $key = $client?->id ? 'developer-client:' . $client->id : 'developer-ip:' . $request->ip();

            return Limit::perMinute($limit)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => [
                            'code' => 'rate_limit_exceeded',
                            'message' => 'Limite de requisições excedido. Aguarde o período indicado pelos headers de rate limit.',
                            'details' => (object) [],
                            'request_id' => $request->attributes->get('request_id'),
                        ],
                    ], 429, $headers);
                });
        });
    }
}
