<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\EcosystemAccountController;
use App\Http\Controllers\EcosystemSsoController;
use App\Http\Controllers\IdentitySessionController;
use App\Http\Controllers\SafeAccountContextController;
use Illuminate\Support\Facades\Route;

// Backward-compatible ecosystem SSO endpoints. New clients should use /identity/v1 below.
Route::post('account/sso/exchange', [EcosystemSsoController::class, 'exchange'])
    ->middleware(['api', 'throttle:identity-exchange'])
    ->name('account.sso.exchange');

Route::get('account/sso/session/csrf', [EcosystemSsoController::class, 'globalSessionCsrf'])
    ->middleware(['api', 'throttle:identity-session'])
    ->name('account.sso.session.csrf');

Route::post('account/sso/session/exchange', [EcosystemSsoController::class, 'exchangeGlobalSession'])
    ->middleware(['api', 'throttle:identity-exchange'])
    ->name('account.sso.session.exchange');

Route::delete('account/sso/session', [EcosystemSsoController::class, 'revokeGlobalSession'])
    ->middleware(['api', 'throttle:identity-security'])
    ->name('account.sso.session.revoke');

Route::prefix('identity/v1')->middleware('api')->group(function () {
    Route::post('/handoff/exchange', [EcosystemSsoController::class, 'exchange'])
        ->middleware('throttle:identity-exchange')
        ->name('identity.v1.handoff.exchange');
    Route::get('/session/csrf', [EcosystemSsoController::class, 'globalSessionCsrf'])
        ->middleware('throttle:identity-session')
        ->name('identity.v1.session.csrf');
    Route::post('/session/exchange', [EcosystemSsoController::class, 'exchangeGlobalSession'])
        ->middleware('throttle:identity-exchange')
        ->name('identity.v1.session.exchange');
    Route::delete('/session', [EcosystemSsoController::class, 'revokeGlobalSession'])
        ->middleware('throttle:identity-security')
        ->name('identity.v1.session.revoke');

    Route::middleware('auth:api')->group(function () {
        Route::post('/session', [EcosystemSsoController::class, 'establishGlobalSession'])
            ->middleware('throttle:identity-session')
            ->name('identity.v1.session.establish');
        Route::post('/handoff', [EcosystemSsoController::class, 'createHandoff'])
            ->middleware('throttle:identity-security')
            ->name('identity.v1.handoff');
        Route::get('/sessions', [IdentitySessionController::class, 'index'])
            ->middleware('throttle:identity-session')
            ->name('identity.v1.sessions.index');
        Route::delete('/sessions/{identitySession}', [IdentitySessionController::class, 'destroy'])
            ->middleware('throttle:identity-security')
            ->name('identity.v1.sessions.destroy');
        Route::delete('/sessions', [IdentitySessionController::class, 'revokeOthers'])
            ->middleware('throttle:identity-security')
            ->name('identity.v1.sessions.others');
        Route::post('/logout', [IdentitySessionController::class, 'logout'])
            ->middleware('throttle:identity-security')
            ->name('identity.v1.logout');
        Route::get('/events', [IdentitySessionController::class, 'events'])
            ->middleware('throttle:identity-session')
            ->name('identity.v1.events');
    });
});

Route::prefix('account')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/ecosystem', [EcosystemAccountController::class, 'show'])->name('account.ecosystem');
    Route::post('/sso/handoff', [EcosystemSsoController::class, 'createHandoff'])
        ->middleware('throttle:identity-security')
        ->name('account.sso.handoff');
    Route::post('/sso/session', [EcosystemSsoController::class, 'establishGlobalSession'])
        ->middleware('throttle:identity-session')
        ->name('account.sso.session.establish');
    Route::get('/context', [SafeAccountContextController::class, 'show'])->name('account.context');
    Route::get('/item-metrics', [AccountController::class, 'itemMetrics'])->name('account.itemMetrics');
    Route::post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::post('/email/request-change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('account.email.requestChange');
    Route::post('/email/confirm-change', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1')->name('account.email.confirmChange');
});
