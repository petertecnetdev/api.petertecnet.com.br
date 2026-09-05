<?php

use App\Domain\Events\Http\Controllers\EventAnnouncementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::middleware('app.capability:events')->group(function () {
            Route::get('/events/public/{slug}/announcements', [EventAnnouncementController::class, 'publicIndex'])
                ->middleware('throttle:120,1');
        });

        Route::middleware(['auth:api', 'token.version', 'app.capability:events'])->group(function () {
            Route::get('/events/{eventId}/announcements/manage', [EventAnnouncementController::class, 'manageIndex'])
                ->whereNumber('eventId');
            Route::post('/events/{eventId}/announcements', [EventAnnouncementController::class, 'store'])
                ->whereNumber('eventId')
                ->middleware('throttle:30,1');
            Route::match(['put', 'patch'], '/events/{eventId}/announcements/{announcementId}', [EventAnnouncementController::class, 'update'])
                ->whereNumber('eventId')
                ->whereNumber('announcementId');
            Route::post('/events/{eventId}/announcements/{announcementId}/publish', [EventAnnouncementController::class, 'publish'])
                ->whereNumber('eventId')
                ->whereNumber('announcementId')
                ->middleware('throttle:20,1');
            Route::post('/events/{eventId}/announcements/{announcementId}/unpublish', [EventAnnouncementController::class, 'unpublish'])
                ->whereNumber('eventId')
                ->whereNumber('announcementId');
            Route::delete('/events/{eventId}/announcements/{announcementId}', [EventAnnouncementController::class, 'destroy'])
                ->whereNumber('eventId')
                ->whereNumber('announcementId');
        });
    });
