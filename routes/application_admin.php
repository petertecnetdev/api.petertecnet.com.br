<?php

use App\Domain\Platform\Http\Controllers\ApplicationAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.admin'])
    ->group(function () {
        Route::prefix('admin')->group(function () {
            Route::get('/overview', [ApplicationAdminController::class, 'overview']);

            Route::get('/users', [ApplicationAdminController::class, 'users']);
            Route::patch('/users/{user}/access', [ApplicationAdminController::class, 'updateUserAccess'])->whereNumber('user');

            Route::get('/productions', [ApplicationAdminController::class, 'productions']);
            Route::patch('/productions/{production}/status', [ApplicationAdminController::class, 'updateProductionStatus'])->whereNumber('production');

            Route::get('/events', [ApplicationAdminController::class, 'events']);
            Route::patch('/events/{event}/status', [ApplicationAdminController::class, 'updateEventStatus'])->whereNumber('event');

            Route::get('/activity', [ApplicationAdminController::class, 'activity']);
        });
    });
