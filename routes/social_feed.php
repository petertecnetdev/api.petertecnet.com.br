<?php

use App\Domain\Events\Http\Controllers\EventCommunityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:event_community'])
    ->group(function () {
        Route::post('/community/{postId}/poll-vote', [EventCommunityController::class, 'votePoll'])
            ->whereNumber('postId')
            ->middleware('throttle:120,1');
    });
