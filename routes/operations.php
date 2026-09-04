<?php

use App\Domain\Operations\Http\Controllers\ResourceOperationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::get('/operations', [ResourceOperationsController::class, 'operations']);
        Route::post('/operations', [ResourceOperationsController::class, 'storeOperation']);
        Route::patch('/operations/{operationId}', [ResourceOperationsController::class, 'updateOperation'])->whereNumber('operationId');

        Route::get('/financial-entries', [ResourceOperationsController::class, 'financialEntries']);
        Route::post('/financial-entries', [ResourceOperationsController::class, 'storeFinancialEntry']);
        Route::patch('/financial-entries/{entryId}', [ResourceOperationsController::class, 'updateFinancialEntry'])->whereNumber('entryId');

        Route::get('/resources/{resourceType}/{resourceId}/timeline', [ResourceOperationsController::class, 'timeline'])->whereNumber('resourceId');
        Route::get('/portfolio/preferences', [ResourceOperationsController::class, 'preferences']);
        Route::put('/portfolio/preferences/{key}', [ResourceOperationsController::class, 'savePreference']);
        Route::get('/portfolio/search', [ResourceOperationsController::class, 'search'])->middleware('throttle:60,1');
    });
