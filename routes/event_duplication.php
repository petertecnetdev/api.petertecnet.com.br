<?php

use App\Domain\Events\Http\Controllers\DuplicateEventController;
use App\Domain\Events\Http\Controllers\EventSeriesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['api', 'app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function (): void {
        Route::post('/events/{id}/duplicate', DuplicateEventController::class)
            ->whereNumber('id');
        Route::post('/events/{id}/series', EventSeriesController::class)
            ->whereNumber('id')
            ->middleware('throttle:20,1');
    });
