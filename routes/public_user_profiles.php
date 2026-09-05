<?php

use App\Domain\People\Http\Controllers\PublicUserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:social'])
    ->group(function () {
        Route::get('/profiles/{userId}', PublicUserProfileController::class)
            ->whereNumber('userId')
            ->middleware('throttle:120,1');
    });
