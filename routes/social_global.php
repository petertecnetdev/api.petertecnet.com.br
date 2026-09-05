<?php

use App\Domain\Social\Http\Controllers\SocialFeedController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::middleware('app.capability:social')->group(function () {
            Route::get('/social/posts', [SocialFeedController::class, 'index'])->middleware('throttle:240,1');

            Route::middleware(['auth:api', 'token.version'])->group(function () {
                Route::post('/social/posts', [SocialFeedController::class, 'store'])->middleware('throttle:30,1');
                Route::delete('/social/posts/{postId}', [SocialFeedController::class, 'destroy'])->whereNumber('postId');
                Route::post('/social/posts/{postId}/like', [SocialFeedController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
                Route::delete('/social/posts/{postId}/like', [SocialFeedController::class, 'unlike'])->whereNumber('postId');
            });
        });
    });
