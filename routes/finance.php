<?php

use App\Domain\Finance\Http\Controllers\FinancialController;
use App\Domain\Finance\Http\Controllers\PayoutController;
use Illuminate\Support\Facades\Route;

// Provider callbacks are global integration endpoints. Application/tenant
// ownership is restored from the persisted financial operation itself.
Route::prefix('finance/webhooks')->group(function () {
    Route::post('/asaas', [FinancialController::class, 'providerWebhook'])
        ->middleware('throttle:240,1')
        ->name('finance.webhooks.asaas');

    Route::post('/asaas/withdrawal-validation', [FinancialController::class, 'withdrawalValidation'])
        ->middleware('throttle:120,1')
        ->name('finance.webhooks.asaas.withdrawal-validation');
});

// Canonical provider-neutral payout lifecycle. Applications opt into the
// capability through configuration; the controller never knows product names.
Route::prefix('v1/apps/{application}/organizations/{organizationId}/payouts')
    ->middleware(['app.context', 'app.capability:payouts', 'auth:api', 'token.version'])
    ->whereNumber('organizationId')
    ->group(function () {
        Route::get('/', [PayoutController::class, 'summary']);
        Route::post('/', [PayoutController::class, 'requestPayout'])->middleware('throttle:10,1');
        Route::post('/{payoutId}/cancel', [PayoutController::class, 'cancel'])
            ->whereNumber('payoutId')
            ->middleware('throttle:10,1');
    });

// Temporary compatibility for already-deployed clients that used the shared
// finance capability before it moved under /api/v1/apps/{application}.
Route::prefix('finance/productions/{organizationId}')
    ->middleware(['api', 'app.bind', 'compatibility.route', 'auth:api', 'token.version'])
    ->whereNumber('organizationId')
    ->group(function () {
        Route::get('/', [FinancialController::class, 'overview']);
        Route::put('/identity', [FinancialController::class, 'saveIdentity'])->middleware('throttle:10,1');
        Route::post('/identity/document', [FinancialController::class, 'uploadDocument'])->middleware('throttle:10,1');
        Route::post('/identity/liveness-session', [FinancialController::class, 'startLiveness'])->middleware('throttle:10,1');
        Route::post('/identity/liveness-complete', [FinancialController::class, 'completeLiveness'])->middleware('throttle:10,1');
        Route::put('/pix', [FinancialController::class, 'savePix'])->middleware('throttle:5,1');
        Route::post('/payouts', [FinancialController::class, 'requestPayout'])->middleware('throttle:5,1');
    });
