<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/subscriptions/catalog', [SubscriptionController::class, 'catalog'])
    ->name('subscriptions.catalog');

Route::middleware('auth:api')->prefix('subscriptions')->group(function () {
    Route::get('/me', [SubscriptionController::class, 'me'])->name('subscriptions.me');
    Route::get('/access/{applicationKey}', [SubscriptionController::class, 'access'])->name('subscriptions.access');
    Route::post('/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:10,1')->name('subscriptions.checkout');
    Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->middleware('throttle:10,1')->name('subscriptions.cancel');
    Route::post('/{subscription}/pause', [SubscriptionController::class, 'pause'])->middleware('throttle:10,1')->name('subscriptions.pause');
    Route::post('/{subscription}/resume', [SubscriptionController::class, 'resume'])->middleware('throttle:10,1')->name('subscriptions.resume');
});

Route::post('/webhooks/mercadopago/subscriptions', [SubscriptionController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('subscriptions.webhook.mercadopago');
