<?php

use App\Domain\Events\Http\Controllers\EventAgendaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function () {
        Route::get('/event-agenda/productions/{productionId}', [EventAgendaController::class, 'index'])
            ->whereNumber('productionId');
        Route::patch('/event-agenda/productions/{productionId}/status', [EventAgendaController::class, 'setAgendaStatus'])
            ->whereNumber('productionId');
        Route::post('/event-agenda/productions/{productionId}/items', [EventAgendaController::class, 'store'])
            ->whereNumber('productionId');
        Route::post('/event-agenda/productions/{productionId}/generate-upcoming', [EventAgendaController::class, 'generateUpcoming'])
            ->whereNumber('productionId')
            ->middleware('throttle:30,1');

        Route::match(['post', 'patch'], '/event-agenda/items/{scheduleId}', [EventAgendaController::class, 'update'])
            ->whereNumber('scheduleId');
        Route::patch('/event-agenda/items/{scheduleId}/status', [EventAgendaController::class, 'setItemStatus'])
            ->whereNumber('scheduleId');
        Route::post('/event-agenda/items/{scheduleId}/generate', [EventAgendaController::class, 'generate'])
            ->whereNumber('scheduleId')
            ->middleware('throttle:60,1');
        Route::delete('/event-agenda/items/{scheduleId}', [EventAgendaController::class, 'destroy'])
            ->whereNumber('scheduleId');
    });
