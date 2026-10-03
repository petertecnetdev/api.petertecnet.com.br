<?php

use App\Domain\Media\Library\Http\Controllers\AdminMediaLibraryController;
use App\Domain\Media\Library\Http\Controllers\PublicMediaLibraryController;
use App\Http\Middleware\PeterTecnetAdminApi;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/media-library')
    ->middleware(['auth:api', PeterTecnetAdminApi::class])
    ->group(function (): void {
        Route::get('/assets', [AdminMediaLibraryController::class, 'index']);
        Route::post('/assets', [AdminMediaLibraryController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/assets/{asset}', [AdminMediaLibraryController::class, 'show'])->whereNumber('asset');
        Route::patch('/assets/{asset}', [AdminMediaLibraryController::class, 'update'])->whereNumber('asset');
        Route::delete('/assets/{asset}', [AdminMediaLibraryController::class, 'destroy'])->whereNumber('asset');

        Route::post('/assets/{asset}/relations', [AdminMediaLibraryController::class, 'addRelation'])->whereNumber('asset');
        Route::delete('/assets/{asset}/relations/{relationId}', [AdminMediaLibraryController::class, 'deleteRelation'])
            ->whereNumber('asset')
            ->whereNumber('relationId');

        Route::get('/collections', [AdminMediaLibraryController::class, 'collections']);
        Route::post('/collections', [AdminMediaLibraryController::class, 'storeCollection']);
        Route::post('/collections/{collection}/assets/{asset}', [AdminMediaLibraryController::class, 'attachCollection'])
            ->whereNumber('collection')
            ->whereNumber('asset');
        Route::delete('/collections/{collection}/assets/{asset}', [AdminMediaLibraryController::class, 'detachCollection'])
            ->whereNumber('collection')
            ->whereNumber('asset');
    });

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function (): void {
        Route::get('/media-library/marketing', [PublicMediaLibraryController::class, 'marketing'])
            ->middleware('throttle:120,1');
    });
