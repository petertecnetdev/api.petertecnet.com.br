<?php

use App\Http\Controllers\AiContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('ai/content')
    ->middleware(['auth:api', 'throttle:20,1'])
    ->group(function () {
        Route::post('/description', [AiContentController::class, 'description'])
            ->name('ai.content.description');
    });
