<?php

use App\Domain\Discovery\Http\Controllers\ContentController;
use App\Domain\Discovery\Http\Controllers\ContentManagementController;
use App\Domain\Discovery\Http\Controllers\DiscoveryAnalyticsController;
use App\Domain\Discovery\Http\Controllers\DiscoveryController;
use App\Domain\Discovery\Http\Controllers\DiscoveryEventController;
use App\Domain\Discovery\Http\Controllers\SocialCardController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/content', [ContentController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/content/{slug}', [ContentController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');

    Route::prefix('discovery')->group(function () {
        Route::get('/categories', [DiscoveryController::class, 'categories'])->middleware('throttle:120,1');
        Route::get('/categories/{slug}', [DiscoveryController::class, 'category'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/establishments/{identifier}', [DiscoveryController::class, 'establishment'])->where('identifier', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/items/{identifier}', [DiscoveryController::class, 'item'])->where('identifier', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/social-card/{type}/{identifier}.png', [SocialCardController::class, 'show'])
            ->where('type', 'content|establishment|item|category')
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1');
        Route::post('/events', [DiscoveryEventController::class, 'store'])->middleware('throttle:180,1');
    });
});

Route::middleware(['auth:api', 'token.version'])->prefix('admin')->group(function () {
    Route::get('/content', [ContentManagementController::class, 'index']);
    Route::post('/content', [ContentManagementController::class, 'store']);
    Route::patch('/content/{content}', [ContentManagementController::class, 'update'])->whereNumber('content');
    Route::post('/content/{content}/publish', [ContentManagementController::class, 'publish'])->whereNumber('content');
    Route::delete('/content/{content}', [ContentManagementController::class, 'destroy'])->whereNumber('content');
    Route::get('/discovery/analytics', [DiscoveryAnalyticsController::class, 'summary']);
});
