<?php

use App\Domain\Commerce\Http\Controllers\CommerceFulfillmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Generic commerce fulfillment contract
|--------------------------------------------------------------------------
| Applications opt in through the commerce capability. These routes extend
| the canonical order contract without reintroducing application-specific
| controllers or legacy /commerce URL prefixes.
*/
Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:commerce'])
    ->group(function () {
        Route::get('/me/orders/{order}/fulfillment/credential', [CommerceFulfillmentController::class, 'credential'])
            ->whereNumber('order')
            ->middleware('throttle:60,1');
        Route::patch('/orders/{order}/fulfillment/status', [CommerceFulfillmentController::class, 'updateStatus'])
            ->whereNumber('order')
            ->middleware('throttle:30,1');
        Route::post('/orders/{order}/fulfillment/verify', [CommerceFulfillmentController::class, 'verify'])
            ->whereNumber('order')
            ->middleware('throttle:30,1');
        Route::post('/orders/{order}/redeem', [CommerceFulfillmentController::class, 'redeem'])
            ->whereNumber('order')
            ->middleware('throttle:15,1');
        Route::get('/orders/{order}/fulfillment/events', [CommerceFulfillmentController::class, 'history'])
            ->whereNumber('order')
            ->middleware('throttle:60,1');
    });
