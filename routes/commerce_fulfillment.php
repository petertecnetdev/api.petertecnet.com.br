<?php

use App\Domain\Commerce\Http\Controllers\CommerceFulfillmentController;
use App\Domain\Commerce\Http\Controllers\EventCatalogController;
use App\Domain\Commerce\Http\Controllers\EventItemRedemptionController;
use App\Domain\Commerce\Http\Controllers\EventPurchaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Generic commerce fulfillment contract
|--------------------------------------------------------------------------
| Applications opt in through the commerce capability. These routes extend
| the canonical order contract without reintroducing legacy product URLs.
*/
Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:events,commerce'])
    ->group(function () {
        Route::get('/events/public/{slug}/purchase-options', [EventPurchaseController::class, 'show'])
            ->middleware('throttle:120,1');
    });

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:commerce'])
    ->group(function () {
        Route::get('/events/{eventId}/catalog-items', [EventCatalogController::class, 'index'])
            ->whereNumber('eventId')
            ->middleware('throttle:60,1');
        Route::put('/events/{eventId}/catalog-items', [EventCatalogController::class, 'sync'])
            ->whereNumber('eventId')
            ->middleware('throttle:30,1');

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

        Route::get('/commerce/orders/{publicId}/pickup-credential', [EventItemRedemptionController::class, 'credential'])
            ->whereUuid('publicId')
            ->middleware('throttle:60,1');
        Route::post('/commerce/item-redemptions/redeem', [EventItemRedemptionController::class, 'redeem'])
            ->middleware('throttle:120,1');
    });
