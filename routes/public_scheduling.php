<?php

use App\Domain\Scheduling\Http\Controllers\AvailabilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:scheduling'])
    ->group(function () {
        Route::post('/public-availability/times', [AvailabilityController::class, 'times'])
            ->middleware('throttle:60,1')
            ->name('scheduling.public-availability.times');
    });
