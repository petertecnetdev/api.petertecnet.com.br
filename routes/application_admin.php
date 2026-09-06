<?php

use App\Domain\Platform\Http\Controllers\ApplicationAdminController;
use App\Http\Middleware\EnsureApplicationAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::prefix('admin')->middleware(EnsureApplicationAdmin::class)->group(function () {
            Route::get('/context', [ApplicationAdminController::class, 'context']);
            Route::get('/permissions', [ApplicationAdminController::class, 'permissionCatalog']);
            Route::get('/audit', [ApplicationAdminController::class, 'audit'])
                ->middleware(EnsureApplicationAdmin::class.':audit.view');

            Route::middleware(EnsureApplicationAdmin::class.':admin.access.manage')->group(function () {
                Route::get('/profiles', [ApplicationAdminController::class, 'profiles']);
                Route::post('/profiles', [ApplicationAdminController::class, 'storeProfile'])->middleware('throttle:30,1');
                Route::put('/profiles/{profileId}', [ApplicationAdminController::class, 'updateProfile'])
                    ->whereNumber('profileId')
                    ->middleware('throttle:30,1');
                Route::delete('/profiles/{profileId}', [ApplicationAdminController::class, 'destroyProfile'])
                    ->whereNumber('profileId')
                    ->middleware('throttle:20,1');

                Route::get('/assignments', [ApplicationAdminController::class, 'assignments']);
                Route::post('/assignments', [ApplicationAdminController::class, 'assign'])->middleware('throttle:30,1');
                Route::delete('/assignments/{assignmentId}', [ApplicationAdminController::class, 'revoke'])
                    ->whereNumber('assignmentId')
                    ->middleware('throttle:30,1');
            });
        });
    });
