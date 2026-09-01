<?php

use App\Http\Controllers\PublicNexusCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('nexus/public')->middleware('api')->group(function () {
    Route::get('/catalog-by-cnpj/{cnpj}', [PublicNexusCatalogController::class, 'byCnpj'])
        ->where('cnpj', '[0-9.\/-]+')
        ->name('nexus.public.catalog-by-cnpj');
});
