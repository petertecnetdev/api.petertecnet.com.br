<?php

use App\Domain\PropertyManagement\Http\Controllers\PropertyManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::prefix('property-management')->group(function () {
            Route::get('/dashboard', [PropertyManagementController::class, 'dashboard']);
            Route::get('/properties', [PropertyManagementController::class, 'properties']);
            Route::post('/properties', [PropertyManagementController::class, 'storeProperty'])->middleware('throttle:30,1');
            Route::get('/agreements', [PropertyManagementController::class, 'agreements']);
            Route::post('/agreements', [PropertyManagementController::class, 'storeAgreement'])->middleware('throttle:20,1');
            Route::get('/agreements/{id}', [PropertyManagementController::class, 'showAgreement'])->whereNumber('id');
            Route::post('/agreements/{id}/signatures', [PropertyManagementController::class, 'sign'])->whereNumber('id')->middleware('throttle:10,1');
            Route::post('/agreements/{id}/generate-contract', [PropertyManagementController::class, 'contractPdf'])->whereNumber('id')->middleware('throttle:20,1');
            Route::get('/receivables', [PropertyManagementController::class, 'receivables']);
            Route::get('/documents', [PropertyManagementController::class, 'documents']);
            Route::post('/documents', [PropertyManagementController::class, 'storeDocument'])->middleware('throttle:20,1');
        });
    });
