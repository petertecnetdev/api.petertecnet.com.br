<?php

use App\Http\Controllers\CutinappController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

Route::get('/cutinapp/config', [CutinappController::class, 'config'])->middleware('api');

Route::prefix('cutinapp')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/productions/mine', [CutinappController::class, 'myProductions']);
    Route::post('/productions', [CutinappController::class, 'createProduction']);

    Route::get('/passes/mine', [EventPassController::class, 'mine']);
    Route::post('/passes/claim/{ticketId}', [EventPassController::class, 'claim'])->whereNumber('ticketId');
    Route::post('/checkin', [EventPassController::class, 'validateToken'])->middleware('throttle:120,1');
    Route::get('/checkin/event/{eventId}/stats', [EventPassController::class, 'eventStats'])->whereNumber('eventId');
});
