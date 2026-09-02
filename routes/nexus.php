<?php

use App\Http\Controllers\Api\V1\DiscoveryController;
use App\Http\Controllers\NexusCatalogCompanyController;
use App\Http\Controllers\NexusShareController;
use Illuminate\Support\Facades\Route;

// Deprecated compatibility routes. Canonical clients use /api/v1/apps/{application}.
Route::prefix('nexus')->middleware(['api', 'app.fixed:nexus'])->group(function () {
    Route::get('/discovery', [DiscoveryController::class, 'index'])->name('nexus.discovery.index');
    Route::get('/search', [DiscoveryController::class, 'search'])->name('nexus.search');

    Route::get('/share/catalog/{identifier}', [NexusShareController::class, 'catalog'])
        ->where('identifier', '[A-Za-z0-9\-]+')->middleware('throttle:120,1')->name('nexus.share.catalog');

    Route::get('/catalog/{identifier}', [NexusCatalogCompanyController::class, 'showCatalog'])
        ->where('identifier', '[A-Za-z0-9\-]+')->name('nexus.catalog.show');

    Route::get('/item/{identifier}', [NexusCatalogCompanyController::class, 'showItem'])
        ->where('identifier', '[A-Za-z0-9\-]+')->name('nexus.item.show');
});

Route::prefix('nexus')->middleware(['api', 'app.fixed:nexus', 'auth:api'])->group(function () {
    Route::get('/catalog-companies', [NexusCatalogCompanyController::class, 'index'])->name('nexus.catalog-companies.index');
    Route::post('/catalog-companies/{sourceId}/activate', [NexusCatalogCompanyController::class, 'activate'])
        ->whereNumber('sourceId')->name('nexus.catalog-companies.activate');
});
