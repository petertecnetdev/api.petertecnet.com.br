<?php

use App\Domain\Events\Http\Controllers\EventAgendaController;
use App\Domain\Events\Http\Controllers\EventSoundtrackController;
use App\Domain\Events\Http\Controllers\RideController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:events'])
    ->group(function () {
        Route::get('/events/public/{slug}/soundtrack', [EventSoundtrackController::class, 'show'])
            ->where('slug', '[A-Za-z0-9\-]+');
    });

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function () {
        Route::get('/event-agenda/productions/{productionId}', [EventAgendaController::class, 'index'])
            ->whereNumber('productionId');
        Route::patch('/event-agenda/productions/{productionId}/status', [EventAgendaController::class, 'setAgendaStatus'])
            ->whereNumber('productionId');
        Route::patch('/event-agenda/productions/{productionId}/settings', [EventAgendaController::class, 'updateSettings'])
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

        Route::put('/events/{id}/soundtrack', [EventSoundtrackController::class, 'update'])
            ->whereNumber('id')
            ->middleware('throttle:60,1');
        Route::post('/events/{id}/soundtrack/upload', [EventSoundtrackController::class, 'upload'])
            ->whereNumber('id')
            ->middleware('throttle:20,1');
        Route::delete('/events/{id}/soundtrack/items/{itemId}', [EventSoundtrackController::class, 'destroyItem'])
            ->whereNumber('id')
            ->where('itemId', '[A-Za-z0-9\-]+')
            ->middleware('throttle:60,1');

        Route::get('/events/{eventId}/rides', [RideController::class, 'index'])
            ->whereNumber('eventId');
        Route::post('/events/{eventId}/rides', [RideController::class, 'store'])
            ->whereNumber('eventId')
            ->middleware('throttle:30,1');
        Route::post('/rides/{rideId}/requests', [RideController::class, 'requestSeat'])
            ->whereNumber('rideId')
            ->middleware('throttle:30,1');
        Route::patch('/rides/{rideId}/requests/{requestId}', [RideController::class, 'respond'])
            ->whereNumber('rideId')
            ->whereNumber('requestId')
            ->middleware('throttle:60,1');
        Route::delete('/rides/{rideId}', [RideController::class, 'cancel'])
            ->whereNumber('rideId')
            ->middleware('throttle:30,1');
    });
