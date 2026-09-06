<?php

use App\Http\Controllers\Admin\AdminEmailVerificationDeferralController;
use App\Http\Controllers\Auth\EmailVerificationDeferralController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get('/email-verification-state', [EmailVerificationDeferralController::class, 'state'])
        ->middleware('throttle:30,1')
        ->name('auth.email-verification.state');

    Route::post('/defer-email-verification', [EmailVerificationDeferralController::class, 'defer'])
        ->middleware('throttle:10,1')
        ->name('auth.email-verification.defer');
});

Route::prefix('admin/ecosystem')
    ->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/users/{user}/email-verification-deferrals', [AdminEmailVerificationDeferralController::class, 'state'])
            ->whereNumber('user')
            ->middleware('throttle:30,1');

        Route::post('/users/{user}/email-verification-deferrals/reset', [AdminEmailVerificationDeferralController::class, 'reset'])
            ->whereNumber('user')
            ->middleware('throttle:10,1');
    });
