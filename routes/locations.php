<?php

use App\Domain\Locations\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}/locations')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/places', [LocationController::class, 'places'])->middleware('throttle:90,1');
        Route::get('/places/{placeId}', [LocationController::class, 'place'])
            ->where('placeId', '[A-Za-z0-9_-]+')
            ->middleware('throttle:90,1');
    });
