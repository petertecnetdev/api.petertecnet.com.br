<?php

use App\Domain\People\Http\Controllers\ArtistWorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')
    ->middleware(['api', 'app.bind:cutinapp', 'compatibility.route'])
    ->group(function (): void {
        Route::post('/artists/{artistId}/analytics/track', [ArtistWorkflowController::class, 'track'])
            ->whereNumber('artistId')
            ->middleware('throttle:120,1');
    });

Route::prefix('cutinapp')
    ->middleware(['api', 'app.bind:cutinapp', 'compatibility.route', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::get('/events/{eventId}/artist-candidates', [ArtistWorkflowController::class, 'candidates'])->whereNumber('eventId')->middleware('throttle:120,1');
        Route::post('/events/{eventId}/artists/resolve', [ArtistWorkflowController::class, 'resolveAndInvite'])->whereNumber('eventId')->middleware('throttle:60,1');
        Route::put('/events/{eventId}/artists/{artistId}/response', [ArtistWorkflowController::class, 'respond'])->whereNumber('eventId')->whereNumber('artistId');
        Route::post('/events/{eventId}/artists/{artistId}/check-in', [ArtistWorkflowController::class, 'checkIn'])->whereNumber('eventId')->whereNumber('artistId');

        Route::post('/artists/{artistId}/favorite', [ArtistWorkflowController::class, 'favorite'])->whereNumber('artistId');
        Route::delete('/artists/{artistId}/favorite', [ArtistWorkflowController::class, 'favorite'])->whereNumber('artistId');
        Route::get('/artist-dashboard', [ArtistWorkflowController::class, 'dashboard']);
        Route::match(['get','post'], '/artists/{artistId}/managers', [ArtistWorkflowController::class, 'managers'])->whereNumber('artistId');
        Route::delete('/artists/{artistId}/managers/{userId}', [ArtistWorkflowController::class, 'removeManager'])->whereNumber('artistId')->whereNumber('userId');
        Route::get('/artists/{artistId}/analytics', [ArtistWorkflowController::class, 'analytics'])->whereNumber('artistId');
    });
