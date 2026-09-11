<?php

use App\Domain\Commerce\Http\Controllers\OrderPaymentRetryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:commerce'])
    ->group(function (): void {
        Route::post('/me/orders/{order}/payment', [OrderPaymentRetryController::class, 'store'])
            ->whereNumber('order')
            ->middleware('throttle:30,1');
    });
