<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\EcosystemAccountController;
use App\Http\Controllers\EcosystemSsoController;
use App\Http\Controllers\SafeAccountContextController;
use Illuminate\Support\Facades\Route;

Route::post('account/sso/exchange', [EcosystemSsoController::class, 'exchange'])
    ->middleware(['api', 'throttle:20,1'])
    ->name('account.sso.exchange');

Route::post('account/sso/session/exchange', [EcosystemSsoController::class, 'exchangeGlobalSession'])
    ->middleware(['api', 'throttle:30,1'])
    ->name('account.sso.session.exchange');

Route::delete('account/sso/session', [EcosystemSsoController::class, 'revokeGlobalSession'])
    ->middleware(['api', 'throttle:30,1'])
    ->name('account.sso.session.revoke');

Route::prefix('account')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/ecosystem', [EcosystemAccountController::class, 'show'])->name('account.ecosystem');
    Route::post('/sso/handoff', [EcosystemSsoController::class, 'createHandoff'])
        ->middleware('throttle:30,1')
        ->name('account.sso.handoff');
    Route::post('/sso/session', [EcosystemSsoController::class, 'establishGlobalSession'])
        ->middleware('throttle:30,1')
        ->name('account.sso.session.establish');
    Route::get('/context', [SafeAccountContextController::class, 'show'])->name('account.context');
    Route::get('/item-metrics', [AccountController::class, 'itemMetrics'])->name('account.itemMetrics');
    Route::post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::post('/email/request-change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('account.email.requestChange');
    Route::post('/email/confirm-change', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1')->name('account.email.confirmChange');
});
