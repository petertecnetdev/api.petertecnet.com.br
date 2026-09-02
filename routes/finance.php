<?php

use App\Domain\Finance\Http\Controllers\FinancialController;
use Illuminate\Support\Facades\Route;

// Provider callbacks are global integration endpoints. They are intentionally
// not tied to an application slug; application/tenant ownership is resolved
// from the persisted financial operation itself.
Route::prefix('finance/webhooks')->group(function () {
    Route::post('/asaas', [FinancialController::class, 'providerWebhook'])
        ->middleware('throttle:240,1')
        ->name('finance.webhooks.asaas');

    Route::post('/asaas/withdrawal-validation', [FinancialController::class, 'withdrawalValidation'])
        ->middleware('throttle:120,1')
        ->name('finance.webhooks.asaas.withdrawal-validation');
});
