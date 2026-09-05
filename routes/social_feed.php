<?php

use App\Domain\Social\Http\Controllers\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'app.capability:social'])
    ->group(function () {
        Route::get('/social/posts', [SocialPostController::class, 'index']);
        Route::post('/social/posts', [SocialPostController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('/social/posts/{postId}', [SocialPostController::class, 'destroy'])
            ->whereNumber('postId')
            ->middleware('throttle:30,1');
        Route::post('/social/posts/{postId}/vote', [SocialPostController::class, 'vote'])
            ->whereNumber('postId')
            ->middleware('throttle:60,1');
    });
