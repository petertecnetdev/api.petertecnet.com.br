<?php

use App\Domain\Social\Http\Controllers\ParticipantSocialController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:social'])
    ->group(function () {
        Route::get('/participants', [ParticipantSocialController::class, 'index'])->middleware('throttle:120,1');
        Route::get('/participants/activity', [ParticipantSocialController::class, 'activity'])->middleware('throttle:120,1');
        Route::get('/participants/me/social-settings', [ParticipantSocialController::class, 'settings'])->middleware('throttle:120,1');
        Route::put('/participants/me/social-settings', [ParticipantSocialController::class, 'updateSettings'])->middleware('throttle:60,1');
        Route::get('/participants/{participantId}', [ParticipantSocialController::class, 'show'])->whereNumber('participantId')->middleware('throttle:120,1');
        Route::post('/participants/{participantId}/follow', [ParticipantSocialController::class, 'follow'])->whereNumber('participantId')->middleware('throttle:60,1');
        Route::delete('/participants/{participantId}/follow', [ParticipantSocialController::class, 'unfollow'])->whereNumber('participantId')->middleware('throttle:60,1');
    });
