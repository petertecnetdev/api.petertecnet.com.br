<?php

use App\Domain\Events\Http\Controllers\BulkDeleteOwnedEventsController;
use App\Domain\Events\Http\Controllers\DuplicateEventController;
use App\Domain\Events\Http\Controllers\EventMediaLibraryController;
use App\Domain\Events\Http\Controllers\EventSeriesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['api', 'app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function (): void {
        Route::delete('/events/mine', BulkDeleteOwnedEventsController::class)
            ->middleware('throttle:10,1');
        Route::post('/events/{id}/duplicate', DuplicateEventController::class)
            ->whereNumber('id');
        Route::post('/events/{id}/series', EventSeriesController::class)
            ->whereNumber('id')
            ->middleware('throttle:20,1');

        Route::get('/event-media', [EventMediaLibraryController::class, 'index']);
        Route::get('/event-media/{eventId}', [EventMediaLibraryController::class, 'show'])
            ->whereNumber('eventId');
        Route::get('/event-media/{eventId}/download', [EventMediaLibraryController::class, 'download'])
            ->whereNumber('eventId')
            ->middleware('throttle:60,1');
    });
