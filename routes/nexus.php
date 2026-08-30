<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NexusCatalogCompanyController;

Route::prefix('nexus')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/catalog-companies', [NexusCatalogCompanyController::class, 'index'])
        ->name('nexus.catalog-companies.index');

    Route::post('/catalog-companies/{sourceId}/activate', [NexusCatalogCompanyController::class, 'activate'])
        ->whereNumber('sourceId')
        ->name('nexus.catalog-companies.activate');
});
