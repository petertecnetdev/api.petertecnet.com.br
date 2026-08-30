<?php

use App\Http\Controllers\PayflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('payflow')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/context', [PayflowController::class, 'contextInfo']);
    Route::get('/dashboard', [PayflowController::class, 'dashboard']);

    Route::get('/contacts', [PayflowController::class, 'contacts']);
    Route::post('/contacts', [PayflowController::class, 'storeContact']);

    Route::get('/opportunities', [PayflowController::class, 'opportunities']);
    Route::post('/opportunities', [PayflowController::class, 'storeOpportunity']);
    Route::patch('/opportunities/{id}/stage', [PayflowController::class, 'updateOpportunityStage'])
        ->whereNumber('id');
});
