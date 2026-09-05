<?php

use App\Domain\Locations\Http\Controllers\ReverseLocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/locations/reverse', [ReverseLocationController::class, 'show'])
            ->middleware('throttle:60,1');
    });
