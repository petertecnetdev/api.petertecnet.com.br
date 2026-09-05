<?php

use App\Domain\Analytics\Http\Controllers\RevenueFunnelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:commerce'])
    ->group(function (): void {
        Route::get('/organizations/{organizationId}/revenue-funnel', [RevenueFunnelController::class, 'show'])
            ->whereNumber('organizationId')
            ->middleware('throttle:60,1');
    });
