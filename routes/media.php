<?php

use App\Domain\Media\Http\Controllers\AmbientMediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/ambient-media/{subjectType}/{subjectId}', [AmbientMediaController::class, 'publicShow'])
            ->where('subjectType', 'organization|event')
            ->whereNumber('subjectId')
            ->middleware('throttle:120,1');

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/ambient-media/{subjectType}/{subjectId}/manage', [AmbientMediaController::class, 'manage'])
                ->where('subjectType', 'organization|event')
                ->whereNumber('subjectId');
            Route::put('/ambient-media/{subjectType}/{subjectId}', [AmbientMediaController::class, 'upsert'])
                ->where('subjectType', 'organization|event')
                ->whereNumber('subjectId')
                ->middleware('throttle:30,1');
            Route::delete('/ambient-media/{subjectType}/{subjectId}', [AmbientMediaController::class, 'destroy'])
                ->where('subjectType', 'organization|event')
                ->whereNumber('subjectId')
                ->middleware('throttle:30,1');
            Route::get('/ambient-media/{subjectType}/{subjectId}/recommendations', [AmbientMediaController::class, 'recommendations'])
                ->where('subjectType', 'organization|event')
                ->whereNumber('subjectId')
                ->middleware('throttle:30,1');
        });
    });
