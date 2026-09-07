<?php

use App\Http\Controllers\Admin\AdminEstablishmentEventController;
use App\Http\Controllers\Admin\AdminEstablishmentResourceController;
use App\Http\Controllers\Admin\AdminEventSeriesController;
use App\Http\Controllers\Admin\EstablishmentEventController as EstablishmentTicketController;
use App\Http\Controllers\Admin\ImportantEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem/establishments/{establishment}/resources')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/context', [AdminEstablishmentResourceController::class, 'context']);
        Route::get('/events', [AdminEstablishmentEventController::class, 'index']);
        Route::delete('/events', [AdminEstablishmentEventController::class, 'destroyMany'])
            ->middleware('throttle:20,1');
        Route::post('/events/{event}/duplicate', [AdminEstablishmentEventController::class, 'duplicate'])
            ->whereNumber('event')
            ->middleware('throttle:30,1');
        Route::post('/events/{event}/series', AdminEventSeriesController::class)
            ->whereNumber('event')
            ->middleware('throttle:20,1');
        Route::post('/events/{event}/tickets', [EstablishmentTicketController::class, 'storeTicket'])
            ->whereNumber('event')
            ->middleware('throttle:30,1');
        Route::put('/events/{event}/tickets/{ticket}', [EstablishmentTicketController::class, 'updateTicket'])
            ->whereNumber('event')
            ->whereNumber('ticket')
            ->middleware('throttle:30,1');
        Route::post('/employers', [AdminEstablishmentResourceController::class, 'storeEmployer'])->middleware('throttle:30,1');
        Route::post('/appointments', [AdminEstablishmentResourceController::class, 'storeAppointment'])->middleware('throttle:30,1');
    });

Route::prefix('admin/ecosystem/important-events')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/', [ImportantEventController::class, 'index']);
        Route::get('/unread-count', [ImportantEventController::class, 'unreadCount']);
        Route::patch('/read-all', [ImportantEventController::class, 'markAllRead'])->middleware('throttle:60,1');
        Route::patch('/{importantEvent}/read', [ImportantEventController::class, 'markRead'])->whereNumber('importantEvent')->middleware('throttle:120,1');
    });

require __DIR__.'/application_admin.php';
