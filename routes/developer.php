<?php

use App\Domain\DeveloperPlatform\Http\Controllers\DeveloperClientController;
use App\Domain\DeveloperPlatform\Http\Controllers\DeveloperMetricsController;
use App\Domain\DeveloperPlatform\Http\Controllers\DeveloperWebhookController;
use App\Domain\DeveloperPlatform\Http\Controllers\PlatformStatusController;
use App\Domain\DeveloperPlatform\Http\Controllers\PublicResourceController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/status', [PlatformStatusController::class, 'show'])->middleware('api.version');
Route::get('/sandbox/v1/status', [PlatformStatusController::class, 'show'])->middleware('api.version');

$publicApiMiddleware = [
    'api.version',
    'developer.key',
    'developer.track',
    'developer.origin',
    'throttle:developer',
];

Route::prefix('v1')->middleware($publicApiMiddleware)->group(function () {
    Route::get('/establishments', [PublicResourceController::class, 'establishments'])->middleware('developer.scope:establishments:read');
    Route::get('/establishments/{slug}', [PublicResourceController::class, 'establishment'])->middleware('developer.scope:establishments:read');
    Route::get('/items', [PublicResourceController::class, 'items'])->middleware('developer.scope:catalog:read');
    Route::get('/items/{slug}', [PublicResourceController::class, 'item'])->middleware('developer.scope:catalog:read');
});

Route::prefix('sandbox/v1')->middleware($publicApiMiddleware)->group(function () {
    Route::get('/establishments', [PublicResourceController::class, 'establishments'])->middleware('developer.scope:establishments:read');
    Route::get('/establishments/{slug}', [PublicResourceController::class, 'establishment'])->middleware('developer.scope:establishments:read');
    Route::get('/items', [PublicResourceController::class, 'items'])->middleware('developer.scope:catalog:read');
    Route::get('/items/{slug}', [PublicResourceController::class, 'item'])->middleware('developer.scope:catalog:read');
});

Route::prefix('v1/developer')
    ->middleware(['auth:api', 'token.version', 'throttle:api'])
    ->group(function () {
        Route::get('/scopes', [DeveloperClientController::class, 'scopes']);
        Route::get('/webhook-events', [DeveloperWebhookController::class, 'events']);

        Route::get('/clients', [DeveloperClientController::class, 'index']);
        Route::post('/clients', [DeveloperClientController::class, 'store']);
        Route::get('/clients/{client}', [DeveloperClientController::class, 'show']);
        Route::patch('/clients/{client}', [DeveloperClientController::class, 'update']);
        Route::delete('/clients/{client}', [DeveloperClientController::class, 'destroy']);
        Route::post('/clients/{client}/keys/rotate', [DeveloperClientController::class, 'rotateKey']);
        Route::delete('/clients/{client}/keys/{key}', [DeveloperClientController::class, 'revokeKey']);

        Route::get('/clients/{client}/metrics', [DeveloperMetricsController::class, 'show']);
        Route::get('/clients/{client}/logs', [DeveloperMetricsController::class, 'logs']);

        Route::get('/clients/{client}/webhooks', [DeveloperWebhookController::class, 'index']);
        Route::post('/clients/{client}/webhooks', [DeveloperWebhookController::class, 'store']);
        Route::patch('/clients/{client}/webhooks/{webhook}', [DeveloperWebhookController::class, 'update']);
        Route::delete('/clients/{client}/webhooks/{webhook}', [DeveloperWebhookController::class, 'destroy']);
        Route::post('/clients/{client}/webhooks/{webhook}/rotate-secret', [DeveloperWebhookController::class, 'rotateSecret']);
        Route::get('/clients/{client}/webhooks/{webhook}/deliveries', [DeveloperWebhookController::class, 'deliveries']);
        Route::post('/clients/{client}/webhooks/test', [DeveloperWebhookController::class, 'test']);
    });
