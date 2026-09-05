<?php

use App\Http\Controllers\Admin\AdminEstablishmentResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem/establishments/{establishment}/resources')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/context', [AdminEstablishmentResourceController::class, 'context']);
        Route::post('/employers', [AdminEstablishmentResourceController::class, 'storeEmployer'])->middleware('throttle:30,1');
        Route::post('/appointments', [AdminEstablishmentResourceController::class, 'storeAppointment'])->middleware('throttle:30,1');
    });
