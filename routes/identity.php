<?php

use App\Http\Controllers\Identity\IdentityAuthenticationController;
use App\Http\Controllers\Identity\IdentityFederatedController;
use App\Http\Controllers\Identity\IdentityPasskeyController;
use App\Http\Controllers\Identity\IdentitySecurityController;
use App\Http\Controllers\Identity\IdentitySessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/identity')->name('identity.')->group(function () {
    Route::post('/login', [IdentityAuthenticationController::class, 'login'])
        ->middleware('throttle:30,1')->name('login');
    Route::post('/google', [IdentityFederatedController::class, 'google'])
        ->middleware('throttle:20,1')->name('google');
    Route::post('/register', [IdentityAuthenticationController::class, 'register'])
        ->middleware('throttle:10,1')->name('register');

    Route::post('/magic-link/request', [IdentityAuthenticationController::class, 'requestMagicLink'])
        ->middleware('throttle:10,1')->name('magic.request');
    Route::post('/magic-link/exchange', [IdentityAuthenticationController::class, 'exchangeMagicLink'])
        ->middleware('throttle:20,1')->name('magic.exchange');

    Route::post('/password-reset/request', [IdentityAuthenticationController::class, 'requestPasswordReset'])
        ->middleware('throttle:10,1')->name('password.request');
    Route::post('/password-reset/exchange', [IdentityAuthenticationController::class, 'resetPassword'])
        ->middleware('throttle:10,1')->name('password.exchange');

    Route::post('/two-factor/verify', [IdentityAuthenticationController::class, 'verifyTwoFactor'])
        ->middleware('throttle:20,1')->name('two-factor.verify');

    Route::post('/passkeys/options', [IdentityPasskeyController::class, 'authenticationOptions'])
        ->middleware('throttle:30,1')->name('passkeys.options');
    Route::post('/passkeys/authenticate', [IdentityPasskeyController::class, 'authenticate'])
        ->middleware('throttle:30,1')->name('passkeys.authenticate');

    Route::middleware(['auth:api', 'token.version'])->group(function () {
        Route::post('/logout', [IdentityAuthenticationController::class, 'logout'])->name('logout');
        Route::post('/refresh', [IdentityAuthenticationController::class, 'refresh'])->name('refresh');

        Route::get('/sessions', [IdentitySessionController::class, 'index'])->name('sessions.index');
        Route::delete('/sessions/{sessionId}', [IdentitySessionController::class, 'destroy'])->name('sessions.destroy');
        Route::delete('/sessions', [IdentitySessionController::class, 'destroyAll'])->name('sessions.destroy-all');
        Route::post('/sessions/revoke-others', [IdentitySessionController::class, 'destroyOthers'])->name('sessions.revoke-others');

        Route::get('/security', [IdentitySecurityController::class, 'show'])->name('security.show');
        Route::post('/two-factor/setup', [IdentitySecurityController::class, 'beginTwoFactor'])
            ->middleware('throttle:10,1')->name('two-factor.setup');
        Route::post('/two-factor/confirm', [IdentitySecurityController::class, 'confirmTwoFactor'])
            ->middleware('throttle:20,1')->name('two-factor.confirm');
        Route::delete('/two-factor', [IdentitySecurityController::class, 'disableTwoFactor'])
            ->middleware('throttle:10,1')->name('two-factor.disable');

        Route::post('/passkeys/registration-options', [IdentityPasskeyController::class, 'registrationOptions'])
            ->middleware('throttle:20,1')->name('passkeys.registration-options');
        Route::post('/passkeys', [IdentityPasskeyController::class, 'register'])
            ->middleware('throttle:20,1')->name('passkeys.store');
        Route::delete('/passkeys/{credentialId}', [IdentityPasskeyController::class, 'destroy'])
            ->whereNumber('credentialId')->name('passkeys.destroy');
    });
});

// Compatibility aliases are intentionally registered after routes/api.php by
// RouteServiceProvider. Existing clients keep their URLs while authentication
// is handled by the generic Identity Platform, preventing 2FA/session bypasses.
Route::post('auth/login', [IdentityAuthenticationController::class, 'login'])
    ->middleware('throttle:30,1')->name('identity.compat.login');
Route::post('auth/google', [IdentityFederatedController::class, 'google'])
    ->middleware('throttle:20,1')->name('identity.compat.google');
