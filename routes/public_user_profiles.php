<?php

use App\Domain\People\Http\Controllers\PublicUserProfileController;
use App\Domain\People\Http\Controllers\PublicUserProfileIndexController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:social'])
    ->group(function () {
        Route::get('/profiles/preview-index', PublicUserProfileIndexController::class)
            ->middleware('throttle:30,1');
        Route::get('/profiles/{userId}', PublicUserProfileController::class)
            ->whereNumber('userId')
            ->middleware('throttle:120,1');
    });