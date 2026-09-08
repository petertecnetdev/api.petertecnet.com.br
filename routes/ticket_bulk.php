<?php

use App\Domain\Events\Http\Controllers\EventTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:event_tickets'])
    ->group(function () {
        Route::get('/tickets/{ticketId}/similar', [EventTicketController::class, 'similar'])->whereNumber('ticketId');
        Route::patch('/tickets/bulk', [EventTicketController::class, 'bulkUpdate']);
    });
