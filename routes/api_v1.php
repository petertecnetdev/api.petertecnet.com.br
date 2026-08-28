<?php

use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\EmployerController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
        Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog']);
        Route::get('/items', [ItemController::class, 'index']);

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/me', [AccountContextController::class, 'show']);
            Route::get('/me/establishments', [EstablishmentController::class, 'mine']);
            Route::post('/establishments', [EstablishmentController::class, 'store']);
            Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);
            Route::delete('/establishments/{establishment}', [EstablishmentController::class, 'destroy']);
            Route::get('/establishments/{establishment}/metrics', [MetricsController::class, 'establishment']);

            Route::get('/establishments/{establishment}/items', [ItemController::class, 'mine']);
            Route::post('/items', [ItemController::class, 'store']);
            Route::patch('/items/{item}', [ItemController::class, 'update']);
            Route::delete('/items/{item}', [ItemController::class, 'destroy']);
            Route::get('/items/{item}/metrics', [MetricsController::class, 'item']);

            Route::get('/establishments/{establishment}/employers', [EmployerController::class, 'index']);
            Route::post('/employers', [EmployerController::class, 'store']);
            Route::delete('/employers/{employer}', [EmployerController::class, 'destroy']);
            Route::get('/employers/{employer}/items', [EmployerController::class, 'items']);
            Route::put('/employers/{employer}/items', [EmployerController::class, 'syncItems']);
            Route::get('/employers/{employer}/metrics', [EmployerController::class, 'metrics']);
        });
    });
