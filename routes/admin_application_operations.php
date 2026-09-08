<?php

use App\Domain\Creative\Http\Controllers\AdminCreativePromptController;
use App\Http\Controllers\Admin\ApplicationOperationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/applications/{application}/operations', [ApplicationOperationsController::class, 'show'])
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

// Application-scoped administration and signed communication tracking are
// separate route contracts, loaded here because this file is part of the
// central API route provider registration.
require_once __DIR__.'/application_admin.php';
require_once __DIR__.'/communications.php';
