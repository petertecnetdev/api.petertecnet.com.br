<?php

use App\Http\Controllers\PlatformHealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('health')->group(function () {
    Route::get('/live', [PlatformHealthController::class, 'live'])->name('health.live');
    Route::get('/ready', [PlatformHealthController::class, 'ready'])->name('health.ready');
});
