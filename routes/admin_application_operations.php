<?php

use App\Domain\Creative\Http\Controllers\AdminCreativePromptController;
use App\Http\Controllers\Admin\ApplicationOperationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/applications/{application}/operations', [ApplicationOperationsController::class, 'show'])
            ->whereNumber('application');
        Route::get('/applications/{application}/runtime', [ApplicationOperationsController::class, 'runtime'])
            ->whereNumber('application');
        Route::put('/applications/{application}/runtime', [ApplicationOperationsController::class, 'updateRuntime'])
            ->whereNumber('application');

        Route::get('/creative/prompts/{key}', [AdminCreativePromptController::class, 'show'])
            ->where('key', '[a-z0-9_-]+');
        Route::put('/creative/prompts/{key}', [AdminCreativePromptController::class, 'update'])
            ->where('key', '[a-z0-9_-]+');
        Route::delete('/creative/prompts/{key}', [AdminCreativePromptController::class, 'reset'])
            ->where('key', '[a-z0-9_-]+');
        Route::post('/creative/images', [AdminCreativePromptController::class, 'image'])
            ->middleware('throttle:6,1');
    });
