<?php

use App\Domain\Events\Http\Controllers\EventAttendanceMetricsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:event_tickets'])
    ->group(function (): void {
        Route::get('/checkin/events/{eventId}/stats', [EventAttendanceMetricsController::class, 'show'])
            ->whereNumber('eventId');
    });

// Temporary compatibility alias for already-deployed Cutinapp clients.
Route::prefix('cutinapp')
    ->middleware(['app.bind:cutinapp', 'compatibility.route', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::get('/checkin/event/{eventId}/stats', [EventAttendanceMetricsController::class, 'show'])
            ->whereNumber('eventId');
    });
