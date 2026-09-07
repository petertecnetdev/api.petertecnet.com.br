<?php

use App\Http\Controllers\Admin\ApplicationOperationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ecosystem')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/applications/{application}/operations', [ApplicationOperationsController::class, 'show'])
            ->whereNumber('application');
    });
