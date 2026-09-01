<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\EcosystemAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('account')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/ecosystem', [EcosystemAccountController::class, 'show'])->name('account.ecosystem');
    Route::get('/context', [AccountController::class, 'context'])->name('account.context');
    Route::get('/item-metrics', [AccountController::class, 'itemMetrics'])->name('account.itemMetrics');
    Route::post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::post('/email/request-change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('account.email.requestChange');
    Route::post('/email/confirm-change', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1')->name('account.email.confirmChange');
});
