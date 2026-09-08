<?php

namespace App\Providers;

use App\Domain\Platform\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(function (): void {
            Route::get('/v1/health/ready', [ReadinessController::class, 'ready'])
                ->name('platform.health.ready');

            Route::prefix('v1/apps/{application}')
                ->middleware(['app.context', 'auth:api', 'token.version'])
                ->group(function (): void {
                    Route::post('/health/mutation-probe', [ReadinessController::class, 'mutationProbe'])
                        ->name('platform.health.mutation-probe');
                });
        });
    }
}
