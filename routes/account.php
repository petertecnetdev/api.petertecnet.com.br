<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDocumentController;
use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\EcosystemAccountController;
use App\Http\Controllers\EcosystemSsoController;
use App\Http\Controllers\SafeAccountContextController;
use App\Http\Controllers\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::post('account/sso/exchange', [EcosystemSsoController::class, 'exchange'])
    ->middleware(['api', 'throttle:20,1'])
    ->name('account.sso.exchange');

Route::prefix('auth/instagram')->middleware('api')->group(function () {
    Route::post('/start', [SocialAuthController::class, 'instagramStart'])
        ->middleware('throttle:30,1')
        ->name('auth.instagram.start');
    Route::post('/callback', [SocialAuthController::class, 'instagramCallback'])
        ->middleware('throttle:30,1')
        ->name('auth.instagram.callback');
    Route::post('/complete', [SocialAuthController::class, 'instagramComplete'])
        ->middleware('throttle:10,1')
        ->name('auth.instagram.complete');
    Route::post('/link', [SocialAuthController::class, 'instagramLink'])
        ->middleware(['auth:api', 'throttle:10,1'])
        ->name('auth.instagram.link');
});

Route::prefix('account')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/ecosystem', [EcosystemAccountController::class, 'show'])->name('account.ecosystem');
    Route::post('/sso/handoff', [EcosystemSsoController::class, 'createHandoff'])
        ->middleware('throttle:30,1')
        ->name('account.sso.handoff');
    Route::get('/context', [SafeAccountContextController::class, 'show'])->name('account.context');
    Route::get('/item-metrics', [AccountController::class, 'itemMetrics'])->name('account.itemMetrics');

    Route::get('/profile', [AccountProfileController::class, 'show'])->name('account.profile.show');
    Route::patch('/profile', [AccountProfileController::class, 'update'])->middleware('throttle:30,1')->name('account.profile.patch');
    Route::post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');

    Route::get('/documents', [AccountDocumentController::class, 'index'])->name('account.documents.index');
    Route::post('/documents', [AccountDocumentController::class, 'store'])->middleware('throttle:20,1')->name('account.documents.store');
    Route::get('/documents/{uuid}/download', [AccountDocumentController::class, 'download'])->middleware('throttle:60,1')->name('account.documents.download');
    Route::delete('/documents/{uuid}', [AccountDocumentController::class, 'destroy'])->middleware('throttle:30,1')->name('account.documents.destroy');

    Route::post('/email/request-change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('account.email.requestChange');
    Route::post('/email/confirm-change', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1')->name('account.email.confirmChange');
});
