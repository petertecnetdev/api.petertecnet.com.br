<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NexusCatalogCompanyController;
use App\Http\Controllers\NexusDiscoveryController;

Route::prefix('nexus')->middleware('api')->group(function () {
    Route::get('/discovery', [NexusDiscoveryController::class, 'index'])
        ->name('nexus.discovery.index');

    Route::get('/search', [NexusDiscoveryController::class, 'search'])
        ->name('nexus.search');
});

Route::prefix('nexus')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/catalog-companies', [NexusCatalogCompanyController::class, 'index'])
        ->name('nexus.catalog-companies.index');

    Route::post('/catalog-companies/{sourceId}/activate', [NexusCatalogCompanyController::class, 'activate'])
        ->whereNumber('sourceId')
        ->name('nexus.catalog-companies.activate');
});
