<?php

use App\Http\Controllers\PayflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('payflow')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/context', [PayflowController::class, 'contextInfo']);
    Route::get('/dashboard', [PayflowController::class, 'dashboard']);

    Route::get('/contacts', [PayflowController::class, 'contacts']);
    Route::post('/contacts', [PayflowController::class, 'storeContact']);
    Route::put('/contacts/{id}', [PayflowController::class, 'updateContact'])->whereNumber('id');
    Route::delete('/contacts/{id}', [PayflowController::class, 'destroyContact'])->whereNumber('id');

    Route::get('/opportunities', [PayflowController::class, 'opportunities']);
    Route::post('/opportunities', [PayflowController::class, 'storeOpportunity']);
    Route::patch('/opportunities/{id}/stage', [PayflowController::class, 'updateOpportunityStage'])->whereNumber('id');

    Route::get('/proposals', [PayflowController::class, 'proposals']);
    Route::post('/proposals', [PayflowController::class, 'storeProposal']);

    Route::get('/charges', [PayflowController::class, 'charges']);
    Route::post('/charges', [PayflowController::class, 'storeCharge']);
    Route::patch('/charges/{id}/paid', [PayflowController::class, 'markChargePaid'])->whereNumber('id');
});
