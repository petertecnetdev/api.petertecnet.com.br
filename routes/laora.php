<?php

use App\Domain\Connections\Http\Controllers\ConnectionController;
use App\Domain\Connections\Http\Controllers\ConnectionModerationController;
use App\Domain\Connections\Http\Controllers\ConnectionPrivacyController;
use Illuminate\Support\Facades\Route;

// Legacy product URL. The application name is intentionally confined to this
// compatibility boundary; all reusable behavior and storage live in Domain/Connections.
Route::prefix('laora')->middleware(['api', 'app.bind:laora', 'auth:api'])->group(function () {
    Route::get('/profile', [ConnectionController::class, 'profile'])->middleware('throttle:120,1');
    Route::put('/profile', [ConnectionController::class, 'updateProfile'])->middleware('throttle:30,1');
    Route::post('/profile/photos', [ConnectionController::class, 'uploadPhoto'])->middleware('throttle:12,1');
    Route::delete('/profile/photos/{photoId}', [ConnectionController::class, 'deletePhoto'])->whereNumber('photoId')->middleware('throttle:30,1');

    Route::get('/discover', [ConnectionController::class, 'discover'])->middleware('throttle:120,1');
    Route::post('/swipes', [ConnectionController::class, 'swipe'])->middleware('throttle:120,1');

    Route::get('/matches', [ConnectionController::class, 'matches'])->middleware('throttle:120,1');
    Route::delete('/matches/{matchId}', [ConnectionController::class, 'unmatch'])->whereNumber('matchId')->middleware('throttle:30,1');
    Route::get('/matches/{matchId}/messages', [ConnectionController::class, 'messages'])->whereNumber('matchId')->middleware('throttle:120,1');
    Route::post('/matches/{matchId}/messages', [ConnectionController::class, 'sendMessage'])->whereNumber('matchId')->middleware('throttle:60,1');

    Route::post('/users/{targetUserId}/block', [ConnectionController::class, 'block'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::delete('/users/{targetUserId}/block', [ConnectionController::class, 'unblock'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::post('/reports', [ConnectionController::class, 'report'])->middleware('throttle:10,1');

    Route::get('/privacy/export', [ConnectionPrivacyController::class, 'export'])->middleware('throttle:5,1');
    Route::delete('/privacy/profile', [ConnectionPrivacyController::class, 'destroyProfile'])->middleware('throttle:3,1');

    Route::get('/admin/reports', [ConnectionModerationController::class, 'reports'])->middleware('throttle:60,1');
    Route::post('/admin/reports/{reportId}/action', [ConnectionModerationController::class, 'act'])->whereNumber('reportId')->middleware('throttle:30,1');
});
