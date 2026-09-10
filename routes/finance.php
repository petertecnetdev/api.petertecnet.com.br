<?php

use App\Domain\Finance\Http\Controllers\FinancialController;
use App\Domain\Finance\Http\Controllers\PayoutController;
use App\Domain\Finance\Http\Controllers\SubscriptionIntentController;
use App\Domain\Finance\Http\Controllers\SubscriptionPaymentController;
use App\Domain\Finance\Http\Controllers\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

Route::get('v1/apps/{application}/subscription-plans', [SubscriptionPlanController::class, 'index'])
    ->where('application', '[A-Za-z0-9._-]+')
    ->middleware('throttle:120,1')
    ->name('finance.subscription-plans.index');

Route::get('v1/subscription-bundles', [SubscriptionPlanController::class, 'bundles'])
    ->middleware('throttle:120,1')
    ->name('finance.subscription-bundles.index');

Route::prefix('v1/apps/{application}/subscription-intents')
    ->where(['application' => '[A-Za-z0-9._-]+'])
    ->middleware(['api', 'auth:api', 'token.version'])
    ->group(function () {
        Route::post('/', [SubscriptionIntentController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('finance.subscription-intents.store');
        Route::get('/{intent}', [SubscriptionIntentController::class, 'show'])
            ->whereUuid('intent')
            ->name('finance.subscription-intents.show');
        Route::post('/{intent}/checkout', [SubscriptionPaymentController::class, 'checkout'])
            ->whereUuid('intent')
            ->middleware('throttle:20,1')
            ->name('finance.subscription-payments.checkout');
        Route::post('/{intent}/sync', [SubscriptionPaymentController::class, 'sync'])
            ->whereUuid('intent')
            ->middleware('throttle:60,1')
            ->name('finance.subscription-payments.sync');
    });

Route::post('v1/subscription-payments/mercadopago/webhook', [SubscriptionPaymentController::class, 'webhook'])
    ->middleware('throttle:240,1')
    ->name('finance.subscription-payments.webhook');

Route::prefix('finance/webhooks')->group(function () {
    Route::post('/asaas', [FinancialController::class, 'providerWebhook'])
        ->middleware('throttle:240,1')
        ->name('finance.webhooks.asaas');

    Route::post('/asaas/withdrawal-validation', [FinancialController::class, 'withdrawalValidation'])
        ->middleware('throttle:120,1')
        ->name('finance.webhooks.asaas.withdrawal-validation');
});

Route::prefix('v1/apps/{application}/organizations/{organizationId}/payouts')
    ->middleware(['app.context', 'app.capability:payouts', 'auth:api', 'token.version'])
    ->where(['organizationId' => '[0-9]+'])
    ->group(function () {
        Route::get('/', [PayoutController::class, 'summary']);
        Route::post('/', [PayoutController::class, 'requestPayout'])->middleware('throttle:10,1');
        Route::post('/{payoutId}/cancel', [PayoutController::class, 'cancel'])
            ->whereNumber('payoutId')
            ->middleware('throttle:10,1');
    });

Route::prefix('finance/productions/{organizationId}')
    ->middleware(['api', 'app.bind', 'compatibility.route', 'auth:api', 'token.version'])
    ->where(['organizationId' => '[0-9]+'])
    ->group(function () {
        Route::get('/', [FinancialController::class, 'overview']);
        Route::put('/identity', [FinancialController::class, 'saveIdentity'])->middleware('throttle:10,1');
        Route::post('/identity/document', [FinancialController::class, 'uploadDocument'])->middleware('throttle:10,1');
        Route::post('/identity/liveness-session', [FinancialController::class, 'startLiveness'])->middleware('throttle:10,1');
        Route::post('/identity/liveness-complete', [FinancialController::class, 'completeLiveness'])->middleware('throttle:10,1');
        Route::put('/pix', [FinancialController::class, 'savePix'])->middleware('throttle:5,1');
        Route::post('/payouts', [FinancialController::class, 'requestPayout'])->middleware('throttle:5,1');
    });
