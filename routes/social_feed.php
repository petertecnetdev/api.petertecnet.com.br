<?php

use App\Domain\Social\Http\Controllers\PublicSocialProfileController;
use App\Domain\Social\Http\Controllers\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::middleware('app.capability:social')->group(function () {
            Route::get('/social/posts', [SocialPostController::class, 'index']);
            Route::post('/social/posts/{postId}/view', [SocialPostController::class, 'recordView'])->whereNumber('postId')->middleware('throttle:240,1');
            Route::get('/social/posts/{postId}/viewers', [SocialPostController::class, 'viewers'])->whereNumber('postId');
            Route::get('/users/{userId}/profile', [PublicSocialProfileController::class, 'show'])->whereNumber('userId');

            Route::middleware(['auth:api', 'token.version'])->group(function () {
                Route::post('/social/posts', [SocialPostController::class, 'create'])->middleware('throttle:30,1');
                Route::delete('/social/posts/{postId}', [SocialPostController::class, 'delete'])->whereNumber('postId');
                Route::post('/social/posts/{postId}/like', [SocialPostController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
                Route::delete('/social/posts/{postId}/like', [SocialPostController::class, 'unlike'])->whereNumber('postId');
            });
        });

        Route::middleware('app.capability:event_community')->group(function () {
            Route::post('/community/{postId}/view', [SocialPostController::class, 'recordEventView'])->whereNumber('postId')->middleware('throttle:240,1');
            Route::get('/community/{postId}/viewers', [SocialPostController::class, 'eventViewers'])->whereNumber('postId');
        });
    });
