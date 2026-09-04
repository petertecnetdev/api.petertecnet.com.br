<?php

use App\Domain\Leasing\Http\Controllers\LeaseContextController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/leasing/context', [LeaseContextController::class, 'show']);
        Route::get('/leasing/context/dashboard', [LeaseContextController::class, 'dashboard']);
    });
