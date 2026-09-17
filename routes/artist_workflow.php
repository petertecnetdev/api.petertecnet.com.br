<?php

use App\Domain\People\Http\Controllers\ArtistInvitationController;
use App\Domain\People\Http\Controllers\ArtistWorkflowController;
use App\Domain\People\Http\Controllers\EventArtistManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function (): void {
        Route::middleware('app.capability:social')->group(function (): void {
            Route::post('/artists/{artistId}/analytics/track', [ArtistWorkflowController::class, 'track'])
                ->whereNumber('artistId')
                ->middleware('throttle:120,1');
        });

        Route::middleware(['auth:api', 'token.version'])->group(function (): void {
            Route::middleware('app.capability:events,social')->group(function (): void {
                Route::get('/events/{eventId}/artist-candidates', [ArtistWorkflowController::class, 'candidates'])
                    ->whereNumber('eventId')
                    ->middleware('throttle:120,1');
                Route::post('/events/{eventId}/artists/resolve', [ArtistInvitationController::class, 'resolve'])
                    ->whereNumber('eventId')
                    ->middleware('throttle:60,1');
                Route::post('/artist-invitations/claim-pending', [ArtistInvitationController::class, 'claimPending'])
                    ->middleware('throttle:30,1');
                Route::put('/events/{eventId}/artists/{artistId}/response', [ArtistWorkflowController::class, 'respond'])
                    ->whereNumber('eventId')
                    ->whereNumber('artistId');
                Route::post('/events/{eventId}/artists/{artistId}/check-in', [ArtistWorkflowController::class, 'checkIn'])
                    ->whereNumber('eventId')
                    ->whereNumber('artistId');
                Route::patch('/events/{eventId}/artists/{artistId}/participation', [EventArtistManagementController::class, 'update'])
                    ->whereNumber('eventId')
                    ->whereNumber('artistId');
                Route::put('/events/{eventId}/artists/reorder', [EventArtistManagementController::class, 'reorder'])
                    ->whereNumber('eventId');
            });

            Route::middleware('app.capability:social')->group(function (): void {
                Route::post('/artists/{artistId}/favorite', [ArtistWorkflowController::class, 'favorite'])->whereNumber('artistId');
                Route::delete('/artists/{artistId}/favorite', [ArtistWorkflowController::class, 'favorite'])->whereNumber('artistId');
                Route::get('/artist-dashboard', [ArtistWorkflowController::class, 'dashboard']);
                Route::match(['get', 'post'], '/artists/{artistId}/managers', [ArtistWorkflowController::class, 'managers'])->whereNumber('artistId');
                Route::delete('/artists/{artistId}/managers/{userId}', [ArtistWorkflowController::class, 'removeManager'])
                    ->whereNumber('artistId')
                    ->whereNumber('userId');
                Route::get('/artists/{artistId}/analytics', [ArtistWorkflowController::class, 'analytics'])->whereNumber('artistId');
            });
        });
    });
