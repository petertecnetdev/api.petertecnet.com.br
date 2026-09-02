<?php

use App\Http\Controllers\FinancialController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->middleware('api')->group(function () {
    Route::post('/webhooks/asaas', [FinancialController::class, 'asaasWebhook'])
        ->middleware('throttle:240,1')
        ->name('finance.webhooks.asaas');
});

Route::prefix('finance')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/productions/{productionId}', [FinancialController::class, 'overview'])
        ->whereNumber('productionId')
        ->name('finance.production.overview');

    Route::put('/productions/{productionId}/identity', [FinancialController::class, 'saveIdentity'])
        ->whereNumber('productionId')
        ->middleware('throttle:10,1')
        ->name('finance.identity.save');

    Route::post('/productions/{productionId}/identity/document', [FinancialController::class, 'uploadDocument'])
        ->whereNumber('productionId')
        ->middleware('throttle:10,1')
        ->name('finance.identity.document');

    Route::post('/productions/{productionId}/identity/liveness-session', [FinancialController::class, 'startLiveness'])
        ->whereNumber('productionId')
        ->middleware('throttle:10,1')
        ->name('finance.identity.liveness.start');

    Route::post('/productions/{productionId}/identity/liveness-complete', [FinancialController::class, 'completeLiveness'])
        ->whereNumber('productionId')
        ->middleware('throttle:10,1')
        ->name('finance.identity.liveness.complete');

    Route::put('/productions/{productionId}/pix', [FinancialController::class, 'savePix'])
        ->whereNumber('productionId')
        ->middleware('throttle:5,1')
        ->name('finance.pix.save');

    Route::post('/productions/{productionId}/payouts', [FinancialController::class, 'requestPayout'])
        ->whereNumber('productionId')
        ->middleware('throttle:5,1')
        ->name('finance.payouts.create');
});
