<?php

use App\Http\Controllers\Api\V1\ApplicationDirectoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/directory', [ApplicationDirectoryController::class, 'index']);

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/directory/companies', [ApplicationDirectoryController::class, 'companies']);
            Route::post('/directory/companies/{sourceId}/activate', [ApplicationDirectoryController::class, 'activateCompany'])->whereNumber('sourceId');
            Route::delete('/directory/companies/{sourceId}/activate', [ApplicationDirectoryController::class, 'deactivateCompany'])->whereNumber('sourceId');
        });
    });
