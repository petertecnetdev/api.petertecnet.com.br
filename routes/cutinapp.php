<?php

use App\Http\Controllers\CutinappController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->middleware('api')->group(function () {
    Route::get('/config', [CutinappController::class, 'config']);
    Route::get('/events', [CutinappController::class, 'publicEvents']);
    Route::get('/events/public/{slug}', [CutinappController::class, 'publicEvent']);
});

Route::prefix('cutinapp')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/productions/mine', [CutinappController::class, 'myProductions']);
    Route::get('/productions/{id}', [CutinappController::class, 'showProduction'])->whereNumber('id');
    Route::post('/productions', [CutinappController::class, 'createProduction']);
    Route::match(['post', 'put'], '/productions/{id}', [CutinappController::class, 'updateProduction'])->whereNumber('id');

    Route::get('/events/mine', [CutinappController::class, 'myEvents']);
    Route::get('/events/show/{id}', [CutinappController::class, 'showEvent'])->whereNumber('id');
    Route::post('/events', [CutinappController::class, 'createEvent']);
    Route::match(['post', 'put'], '/events/{id}', [CutinappController::class, 'updateEvent'])->whereNumber('id');
    Route::post('/courtesies', [CutinappController::class, 'createCourtesy']);

    Route::get('/passes/mine', [EventPassController::class, 'mine']);
    Route::get('/events/{eventId}/participants', [EventPassController::class, 'participants'])->whereNumber('eventId');
    Route::post('/passes/claim/{ticketId}', [EventPassController::class, 'claim'])->whereNumber('ticketId');
    Route::post('/checkin', [EventPassController::class, 'validateToken'])->middleware('throttle:120,1');
    Route::get('/checkin/event/{eventId}/stats', [EventPassController::class, 'eventStats'])->whereNumber('eventId');
});
