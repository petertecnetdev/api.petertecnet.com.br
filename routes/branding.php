<?php

use App\Http\Controllers\Admin\ApplicationBrandingController as AdminApplicationBrandingController;
use App\Http\Controllers\ApplicationBrandingController;
use Illuminate\Support\Facades\Route;

Route::get('/applications/{slug}/branding', [ApplicationBrandingController::class, 'show'])
    ->where('slug', '[A-Za-z0-9\-]+')
    ->middleware('throttle:60,1')
    ->name('applications.branding.show');

Route::prefix('admin/applications/{application}/branding')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/', [AdminApplicationBrandingController::class, 'show'])
            ->whereNumber('application')
            ->name('admin.applications.branding.show');
        Route::put('/draft', [AdminApplicationBrandingController::class, 'updateDraft'])
            ->whereNumber('application')
            ->name('admin.applications.branding.draft.update');
        Route::delete('/draft', [AdminApplicationBrandingController::class, 'discardDraft'])
            ->whereNumber('application')
            ->name('admin.applications.branding.draft.delete');
        Route::post('/assets', [AdminApplicationBrandingController::class, 'uploadAsset'])
            ->whereNumber('application')
            ->middleware('throttle:30,1')
            ->name('admin.applications.branding.assets.store');
        Route::post('/publish', [AdminApplicationBrandingController::class, 'publish'])
            ->whereNumber('application')
            ->name('admin.applications.branding.publish');
        Route::post('/history/{revision}/restore', [AdminApplicationBrandingController::class, 'restore'])
            ->whereNumber('application')
            ->whereNumber('revision')
            ->name('admin.applications.branding.history.restore');
    });
