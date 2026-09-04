<?php

use App\Domain\Commerce\Http\Controllers\CommerceRefundController;
use App\Domain\Events\Http\Controllers\EventLifecycleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::middleware('app.capability:events')->group(function () {
            Route::get('/events/{id}/lifecycle', [EventLifecycleController::class, 'show'])->whereNumber('id');
            Route::post('/events/{id}/cancel', [EventLifecycleController::class, 'cancel'])->whereNumber('id')->middleware('throttle:10,1');
            Route::post('/events/{id}/postpone', [EventLifecycleController::class, 'postpone'])->whereNumber('id')->middleware('throttle:10,1');
            Route::post('/events/{id}/reschedule', [EventLifecycleController::class, 'reschedule'])->whereNumber('id')->middleware('throttle:10,1');
        });

        Route::middleware('app.capability:events,commerce')->group(function () {
            Route::get('/commerce/orders/{publicId}/refund', [CommerceRefundController::class, 'show']);
            Route::post('/commerce/orders/{publicId}/refund', [CommerceRefundController::class, 'request'])->middleware('throttle:5,1');
            Route::post('/commerce/refunds/{refundId}/retry', [CommerceRefundController::class, 'retry'])->whereNumber('refundId')->middleware('throttle:5,1');
        });
    });
