<?php

use App\Http\Controllers\Admin\AdminEstablishmentEventController;
use App\Http\Controllers\Admin\AdminEstablishmentResourceController;
use App\Http\Controllers\Admin\EstablishmentEventController as EstablishmentTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem/establishments/{establishment}/resources')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/context', [AdminEstablishmentResourceController::class, 'context']);
        Route::get('/events', [AdminEstablishmentEventController::class, 'index']);
        Route::post('/events/{event}/duplicate', [AdminEstablishmentEventController::class, 'duplicate'])
            ->whereNumber('event')
            ->middleware('throttle:30,1');
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

// Application-scoped administrative access is loaded from the same registered
// API route module so the Peter Tecnet root-admin middleware above stays isolated.
require __DIR__.'/application_admin.php';
