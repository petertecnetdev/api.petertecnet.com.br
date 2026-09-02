<?php

use App\Http\Controllers\LaoraController;
use App\Http\Controllers\LaoraModerationController;
use App\Http\Controllers\LaoraPrivacyController;
use Illuminate\Support\Facades\Route;

Route::prefix('laora')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/profile', [LaoraController::class, 'profile'])->middleware('throttle:120,1');
    Route::put('/profile', [LaoraController::class, 'updateProfile'])->middleware('throttle:30,1');
    Route::post('/profile/photos', [LaoraController::class, 'uploadPhoto'])->middleware('throttle:12,1');
    Route::patch('/profile/photos/reorder', [LaoraController::class, 'reorderPhotos'])->middleware('throttle:30,1');
    Route::delete('/profile/photos/{photoId}', [LaoraController::class, 'deletePhoto'])->whereNumber('photoId')->middleware('throttle:30,1');

    Route::get('/discover', [LaoraController::class, 'discover'])->middleware('throttle:120,1');
    Route::post('/swipes', [LaoraController::class, 'swipe'])->middleware('throttle:120,1');

    Route::get('/matches', [LaoraController::class, 'matches'])->middleware('throttle:120,1');
    Route::delete('/matches/{matchId}', [LaoraController::class, 'unmatch'])->whereNumber('matchId')->middleware('throttle:30,1');
    Route::get('/matches/{matchId}/messages', [LaoraController::class, 'messages'])->whereNumber('matchId')->middleware('throttle:120,1');
    Route::post('/matches/{matchId}/messages', [LaoraController::class, 'sendMessage'])->whereNumber('matchId')->middleware('throttle:60,1');

    Route::post('/users/{targetUserId}/block', [LaoraController::class, 'block'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::delete('/users/{targetUserId}/block', [LaoraController::class, 'unblock'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::post('/reports', [LaoraController::class, 'report'])->middleware('throttle:10,1');

    Route::get('/privacy/export', [LaoraPrivacyController::class, 'export'])->middleware('throttle:5,1');
    Route::delete('/privacy/profile', [LaoraPrivacyController::class, 'destroyProfile'])->middleware('throttle:3,1');

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [LaoraModerationController::class, 'dashboard'])->middleware('throttle:60,1');
        Route::get('/reports', [LaoraModerationController::class, 'reports'])->middleware('throttle:60,1');
        Route::post('/reports/{reportId}/action', [LaoraModerationController::class, 'act'])->whereNumber('reportId')->middleware('throttle:30,1');
        Route::get('/photos', [LaoraModerationController::class, 'photos'])->middleware('throttle:60,1');
        Route::post('/photos/{photoId}/moderate', [LaoraModerationController::class, 'moderatePhoto'])->whereNumber('photoId')->middleware('throttle:60,1');
        Route::get('/users/{userId}', [LaoraModerationController::class, 'user'])->whereNumber('userId')->middleware('throttle:60,1');
    });
});
