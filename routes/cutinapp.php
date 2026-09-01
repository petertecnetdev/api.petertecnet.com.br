<?php

use App\Http\Controllers\CutinappController;
use App\Http\Controllers\CutinappDiscoveryController;
use App\Http\Controllers\CutinappEventController;
use App\Http\Controllers\CutinappLocationController;
use App\Http\Controllers\CutinappPublicProductionController;
use App\Http\Controllers\CutinappPublicSocialController;
use App\Http\Controllers\CutinappSocialController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->middleware('api')->group(function () {
    Route::get('/config', [CutinappController::class, 'config']);
    Route::get('/locations/states', [CutinappLocationController::class, 'states']);
    Route::get('/locations/cities', [CutinappLocationController::class, 'cities'])->middleware('throttle:120,1');
    Route::get('/locations/cep/{cep}', [CutinappLocationController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:60,1');
    Route::get('/events', [CutinappDiscoveryController::class, 'events']);
    Route::get('/discovery/facets', [CutinappDiscoveryController::class, 'facets']);
    Route::get('/events/public/{slug}', [CutinappEventController::class, 'publicEvent']);
    Route::get('/events/public/{slug}/artists', [CutinappPublicSocialController::class, 'eventArtists']);
    Route::get('/artists', [CutinappSocialController::class, 'artists']);
    Route::get('/artists/{slug}', [CutinappSocialController::class, 'publicArtist']);
    Route::get('/productions/public/{slug}', [CutinappPublicProductionController::class, 'show']);
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
    Route::get('/artists/mine/list', [CutinappSocialController::class, 'myArtists']);
    Route::post('/artists', [CutinappSocialController::class, 'storeArtist']);
    Route::match(['post', 'put'], '/artists/{id}', [CutinappSocialController::class, 'updateArtist'])->whereNumber('id');
    Route::get('/events/{eventId}/artists', [CutinappSocialController::class, 'eventArtists'])->whereNumber('eventId');
    Route::post('/events/{eventId}/artists', [CutinappSocialController::class, 'attachArtist'])->whereNumber('eventId');
    Route::delete('/events/{eventId}/artists/{artistId}', [CutinappSocialController::class, 'detachArtist'])->whereNumber('eventId')->whereNumber('artistId');
    Route::post('/follow', [CutinappSocialController::class, 'follow']);
    Route::delete('/follow', [CutinappSocialController::class, 'unfollow']);
    Route::match(['get', 'put'], '/preferences', [CutinappSocialController::class, 'preferences']);
    Route::put('/events/{eventId}/engagement', [CutinappSocialController::class, 'engagement'])->whereNumber('eventId');
    Route::get('/feed', [CutinappSocialController::class, 'feed']);
    Route::get('/notifications', [CutinappSocialController::class, 'notifications']);
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
