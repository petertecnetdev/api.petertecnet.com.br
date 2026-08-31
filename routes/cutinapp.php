<?php

use App\Http\Controllers\CutinappController;
use App\Http\Controllers\CutinappEventController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->middleware('api')->group(function () {
    Route::get('/config', [CutinappController::class, 'config']);
    Route::get('/events', [CutinappEventController::class, 'publicEvents']);
    Route::get('/events/public/{slug}', [CutinappEventController::class, 'publicEvent']);
});

Route::prefix('cutinapp')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/productions/mine', [CutinappController::class, 'myProductions']);
    Route::get('/productions/{id}', [CutinappController::class, 'showProduction'])->whereNumber('id');
    Route::post('/productions', [CutinappController::class, 'createProduction']);
    Route::match(['post', 'put'], '/productions/{id}', [CutinappController::class, 'updateProduction'])->whereNumber('id');

    Route::get('/events/mine', [CutinappEventController::class, 'mine']);
    Route::get('/events/show/{id}', [CutinappEventController::class, 'show'])->whereNumber('id');
    Route::post('/events', [CutinappEventController::class, 'store']);
    Route::match(['post', 'put'], '/events/{id}', [CutinappEventController::class, 'update'])->whereNumber('id');
    Route::post('/events/{id}/publish', [CutinappEventController::class, 'publish'])->whereNumber('id');
    Route::post('/events/{id}/unpublish', [CutinappEventController::class, 'unpublish'])->whereNumber('id');

    Route::post('/courtesies', [CutinappController::class, 'createCourtesy']);
    Route::get('/events/{eventId}/courtesies', [CutinappController::class, 'eventCourtesies'])->whereNumber('eventId');
    Route::match(['post', 'put'], '/courtesies/{ticketId}', [CutinappController::class, 'updateCourtesy'])->whereNumber('ticketId');
    Route::delete('/courtesies/{ticketId}', [CutinappController::class, 'deleteCourtesy'])->whereNumber('ticketId');

    Route::get('/passes/mine', [EventPassController::class, 'mine']);
    Route::get('/passes/{passId}', [EventPassController::class, 'show'])->whereNumber('passId');
    Route::get('/events/{eventId}/participants', [EventPassController::class, 'participants'])->whereNumber('eventId');
    Route::post('/passes/claim/{ticketId}', [EventPassController::class, 'claim'])->whereNumber('ticketId');
    Route::post('/checkin', [EventPassController::class, 'validateToken'])->middleware('throttle:120,1');
    Route::get('/checkin/event/{eventId}/stats', [EventPassController::class, 'eventStats'])->whereNumber('eventId');
});
