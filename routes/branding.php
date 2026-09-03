<?php

use App\Http\Controllers\Admin\ApplicationBrandingController as AdminApplicationBrandingController;
use App\Http\Controllers\ApplicationBrandingController;
use Illuminate\Support\Facades\Route;

Route::get('/applications/{slug}/branding', [ApplicationBrandingController::class, 'show'])
    ->where('slug', '[A-Za-z0-9\-]+')
    ->middleware('throttle:60,1')
    ->name('applications.branding.show');

Route::prefix('admin/applications/{application}/branding')
    ->whereNumber('application')
    ->middleware('auth:api')
    ->group(function () {
        Route::get('/', [AdminApplicationBrandingController::class, 'show'])
            ->name('admin.applications.branding.show');
        Route::put('/draft', [AdminApplicationBrandingController::class, 'updateDraft'])
            ->name('admin.applications.branding.draft.update');
        Route::delete('/draft', [AdminApplicationBrandingController::class, 'discardDraft'])
            ->name('admin.applications.branding.draft.delete');
        Route::post('/assets', [AdminApplicationBrandingController::class, 'uploadAsset'])
            ->middleware('throttle:30,1')
            ->name('admin.applications.branding.assets.store');
        Route::post('/publish', [AdminApplicationBrandingController::class, 'publish'])
            ->name('admin.applications.branding.publish');
        Route::post('/history/{revision}/restore', [AdminApplicationBrandingController::class, 'restore'])
            ->whereNumber('revision')
            ->name('admin.applications.branding.history.restore');
    });
