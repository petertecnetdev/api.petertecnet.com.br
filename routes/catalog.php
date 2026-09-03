<?php

use App\Http\Controllers\Api\V1\CatalogDirectoryController;
use App\Http\Controllers\Api\V1\CatalogShareController;
use App\Http\Controllers\Api\V1\DiscoveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}/directory')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/', [DiscoveryController::class, 'index'])
            ->name('v1.directory.index');
        Route::get('/search', [DiscoveryController::class, 'search'])
            ->name('v1.directory.search');
        Route::get('/catalog/{identifier}', [CatalogDirectoryController::class, 'catalog'])
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->name('v1.directory.catalog.show');
        Route::get('/items/{identifier}', [CatalogDirectoryController::class, 'item'])
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->name('v1.directory.items.show');
        Route::get('/share/catalog/{identifier}', [CatalogShareController::class, 'catalog'])
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1')
            ->name('v1.directory.share.catalog');
        Route::get('/share/establishment/{identifier}', [CatalogShareController::class, 'establishment'])
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1')
            ->name('v1.directory.share.establishment');
        Route::get('/share/item/{identifier}', [CatalogShareController::class, 'item'])
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1')
            ->name('v1.directory.share.item');

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/companies', [CatalogDirectoryController::class, 'companies'])
                ->name('v1.directory.companies.index');
            Route::post('/companies/{sourceId}/activate', [CatalogDirectoryController::class, 'activate'])
                ->whereNumber('sourceId')
                ->name('v1.directory.companies.activate');
            Route::delete('/companies/{sourceId}/activate', [CatalogDirectoryController::class, 'deactivate'])
                ->whereNumber('sourceId')
                ->name('v1.directory.companies.deactivate');
        });
    });

// Transitional aliases for already published Nexus links/builds. The implementation
// is fully generic; these routes can be removed after access telemetry confirms that
// old clients and social-share crawlers no longer use them.
Route::prefix('nexus')->group(function () {
    Route::get('/discovery', [DiscoveryController::class, 'index'])
        ->name('compat.nexus.discovery.index');
    Route::get('/search', [DiscoveryController::class, 'search'])
        ->name('compat.nexus.search');
    Route::get('/share/catalog/{identifier}', [CatalogShareController::class, 'catalog'])
        ->where('identifier', '[A-Za-z0-9\-]+')
        ->middleware('throttle:120,1')
        ->name('compat.nexus.share.catalog');
    Route::get('/catalog/{identifier}', [CatalogDirectoryController::class, 'catalog'])
        ->where('identifier', '[A-Za-z0-9\-]+')
        ->name('compat.nexus.catalog.show');
    Route::get('/item/{identifier}', [CatalogDirectoryController::class, 'item'])
        ->where('identifier', '[A-Za-z0-9\-]+')
        ->name('compat.nexus.item.show');

    Route::middleware('auth:api')->group(function () {
        Route::get('/catalog-companies', [CatalogDirectoryController::class, 'companies'])
            ->name('compat.nexus.catalog-companies.index');
        Route::post('/catalog-companies/{sourceId}/activate', [CatalogDirectoryController::class, 'activate'])
            ->whereNumber('sourceId')
            ->name('compat.nexus.catalog-companies.activate');
    });
});
