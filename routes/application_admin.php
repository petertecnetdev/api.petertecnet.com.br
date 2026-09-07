<?php

use App\Domain\Commerce\Http\Controllers\ApplicationAdminCommerceController;
use App\Domain\Events\Http\Controllers\ApplicationAdminAccessController;
use App\Domain\Events\Http\Controllers\ApplicationAdminEventController;
use App\Domain\Events\Http\Controllers\ApplicationAdminTicketController;
use App\Domain\Platform\Http\Controllers\ApplicationAdminController;
use App\Domain\Platform\Http\Controllers\ApplicationAdminProductionController;
use App\Http\Middleware\EnsureApplicationAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::prefix('admin')->middleware(EnsureApplicationAdmin::class)->group(function () {
            Route::get('/context', [ApplicationAdminController::class, 'context']);
            Route::get('/overview', [ApplicationAdminController::class, 'overview'])
                ->middleware(EnsureApplicationAdmin::class.':dashboard.view');
            Route::get('/permissions', [ApplicationAdminController::class, 'permissionCatalog']);
            Route::get('/audit', [ApplicationAdminController::class, 'audit'])
                ->middleware(EnsureApplicationAdmin::class.':audit.view');

            Route::get('/users', [ApplicationAdminController::class, 'users'])
                ->middleware(EnsureApplicationAdmin::class.':users.view');
            Route::post('/users', [ApplicationAdminController::class, 'storeUser'])
                ->middleware([EnsureApplicationAdmin::class.':users.manage', 'throttle:20,1']);

            Route::get('/productions', [ApplicationAdminProductionController::class, 'index'])
                ->middleware(EnsureApplicationAdmin::class.':establishments.view');
            Route::put('/productions/{production}', [ApplicationAdminProductionController::class, 'update'])
                ->whereNumber('production')
                ->middleware([EnsureApplicationAdmin::class.':establishments.manage', 'throttle:30,1']);

            Route::get('/events', [ApplicationAdminEventController::class, 'index'])
                ->middleware(EnsureApplicationAdmin::class.':events.view');
            Route::post('/events/{event}/series', [ApplicationAdminEventController::class, 'series'])
                ->whereNumber('event')
                ->middleware([EnsureApplicationAdmin::class.':events.manage', 'throttle:20,1']);

            Route::get('/tickets', [ApplicationAdminTicketController::class, 'index'])
                ->middleware(EnsureApplicationAdmin::class.':tickets.view');
            Route::put('/tickets/{ticket}', [ApplicationAdminTicketController::class, 'update'])
                ->whereNumber('ticket')
                ->middleware([EnsureApplicationAdmin::class.':tickets.manage', 'throttle:30,1']);
            Route::delete('/tickets/{ticket}', [ApplicationAdminTicketController::class, 'destroy'])
                ->whereNumber('ticket')
                ->middleware([EnsureApplicationAdmin::class.':tickets.manage', 'throttle:20,1']);

            Route::get('/checkins', [ApplicationAdminAccessController::class, 'index'])
                ->middleware(EnsureApplicationAdmin::class.':checkin.view');
            Route::put('/passes/{pass}/invalidate', [ApplicationAdminAccessController::class, 'invalidate'])
                ->whereNumber('pass')
                ->middleware([EnsureApplicationAdmin::class.':checkin.manage', 'throttle:30,1']);

            Route::get('/orders', [ApplicationAdminCommerceController::class, 'orders'])
                ->middleware(EnsureApplicationAdmin::class.':finance.view');
            Route::put('/orders/{order}', [ApplicationAdminCommerceController::class, 'updateOrder'])
                ->whereNumber('order')
                ->middleware([EnsureApplicationAdmin::class.':finance.refund', 'throttle:30,1']);
            Route::get('/finance', [ApplicationAdminCommerceController::class, 'finance'])
                ->middleware(EnsureApplicationAdmin::class.':finance.view');

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
