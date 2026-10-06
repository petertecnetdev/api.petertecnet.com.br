<?php

use App\Http\Controllers\SystemHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [SystemHealthController::class, 'live'])
    ->middleware('throttle:120,1')
    ->name('health.live');

Route::get('/health/ready', [SystemHealthController::class, 'ready'])
    ->middleware('throttle:120,1')
    ->name('health.ready');
