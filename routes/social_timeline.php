<?php

use App\Domain\Social\Http\Controllers\SocialTimelineController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:social'])
    ->group(function () {
        Route::get('/timeline', [SocialTimelineController::class, 'index'])->middleware('throttle:240,1');
        Route::post('/timeline/posts', [SocialTimelineController::class, 'store'])->middleware('throttle:24,1');
        Route::delete('/timeline/posts/{postId}', [SocialTimelineController::class, 'destroy'])->whereNumber('postId')->middleware('throttle:30,1');
        Route::post('/timeline/posts/{postId}/comments', [SocialTimelineController::class, 'comment'])->whereNumber('postId')->middleware('throttle:60,1');
        Route::put('/timeline/posts/{postId}/reaction', [SocialTimelineController::class, 'react'])->whereNumber('postId')->middleware('throttle:180,1');
        Route::delete('/timeline/posts/{postId}/reaction', [SocialTimelineController::class, 'unreact'])->whereNumber('postId')->middleware('throttle:180,1');
        Route::put('/timeline/posts/{postId}/save', [SocialTimelineController::class, 'save'])->whereNumber('postId')->middleware('throttle:120,1');
        Route::delete('/timeline/posts/{postId}/save', [SocialTimelineController::class, 'unsave'])->whereNumber('postId')->middleware('throttle:120,1');
        Route::post('/timeline/posts/{postId}/share', [SocialTimelineController::class, 'share'])->whereNumber('postId')->middleware('throttle:120,1');
        Route::post('/timeline/posts/{postId}/vote', [SocialTimelineController::class, 'vote'])->whereNumber('postId')->middleware('throttle:90,1');
        Route::post('/timeline/posts/{postId}/report', [SocialTimelineController::class, 'report'])->whereNumber('postId')->middleware('throttle:10,1');
        Route::post('/timeline/posts/{postId}/track', [SocialTimelineController::class, 'track'])->whereNumber('postId')->middleware('throttle:300,1');
        Route::post('/timeline/posts/{postId}/boosts', [SocialTimelineController::class, 'requestBoost'])->whereNumber('postId')->middleware('throttle:10,1');
        Route::get('/timeline/analytics', [SocialTimelineController::class, 'analytics'])->middleware('throttle:120,1');
        Route::get('/timeline/moderation/reports', [SocialTimelineController::class, 'moderationIndex'])->middleware('throttle:60,1');
        Route::put('/timeline/moderation/reports/{reportId}', [SocialTimelineController::class, 'moderate'])->whereNumber('reportId')->middleware('throttle:30,1');
    });
