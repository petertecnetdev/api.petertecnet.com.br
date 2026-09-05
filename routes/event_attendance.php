<?php

use App\Domain\Events\Http\Controllers\EventAttendanceMetricsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:event_tickets'])
    ->group(function (): void {
        Route::get('/checkin/events/{eventId}/stats', [EventAttendanceMetricsController::class, 'show'])
            ->whereNumber('eventId');
    });
