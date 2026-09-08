<?php

use App\Domain\Creative\Http\Controllers\CreativeGenerationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::get('/creative/presets', [CreativeGenerationController::class, 'presets'])
            ->middleware('throttle:30,1');

        Route::post('/creative/images', [CreativeGenerationController::class, 'image'])
            ->middleware('throttle:6,1');
    });
