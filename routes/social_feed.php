<?php

use App\Domain\Social\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:social'])
    ->group(function () {
        Route::post('/feed/posts', [FeedController::class, 'store'])
            ->middleware('throttle:30,1');
    });
