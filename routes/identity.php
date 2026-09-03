<?php

use App\Http\Controllers\Identity\IdentityAccountMergeController;
use App\Http\Controllers\Identity\IdentityAuditController;
use App\Http\Controllers\Identity\IdentityAuthenticationController;
use App\Http\Controllers\Identity\IdentityCompatibilityController;
use App\Http\Controllers\Identity\IdentityContactController;
use App\Http\Controllers\Identity\IdentityFederatedController;
use App\Http\Controllers\Identity\IdentityGlobalSsoController;
use App\Http\Controllers\Identity\IdentityPasskeyController;
use App\Http\Controllers\Identity\IdentityProtectionController;
use App\Http\Controllers\Identity\IdentitySecurityController;
use App\Http\Controllers\Identity\IdentitySessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/identity')->name('identity.')->group(function () {
    Route::get('/capabilities', [IdentityProtectionController::class, 'capabilities'])
        ->middleware('throttle:60,1')->name('capabilities');

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

    Route::post('/security/not-me', [IdentityProtectionController::class, 'reportNotMe'])
        ->middleware('throttle:10,1')->name('security.not-me');
    Route::post('/contact/email/confirm', [IdentityContactController::class, 'confirmEmailChange'])
        ->middleware('throttle:10,1')->name('contact.email.confirm');
    Route::post('/account-merge/confirm', [IdentityAccountMergeController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('account-merge.confirm');

    Route::get('/sso/csrf', [IdentityGlobalSsoController::class, 'csrf'])
        ->middleware('throttle:60,1')->name('sso.csrf');
    Route::post('/sso/exchange', [IdentityGlobalSsoController::class, 'exchange'])
        ->middleware('throttle:60,1')->name('sso.exchange');
    Route::delete('/sso/session', [IdentityGlobalSsoController::class, 'revokeCurrent'])
        ->middleware('throttle:20,1')->name('sso.revoke');

    Route::middleware(['auth:api', 'token.version'])->group(function () {
        Route::post('/logout', [IdentityAuthenticationController::class, 'logout'])->name('logout');
        Route::post('/refresh', [IdentityAuthenticationController::class, 'refresh'])->name('refresh');
        Route::post('/logout-everywhere', [IdentityGlobalSsoController::class, 'logoutEverywhere'])
            ->middleware('throttle:10,1')->name('logout-everywhere');
        Route::post('/sso/session', [IdentityGlobalSsoController::class, 'establish'])
            ->middleware('throttle:60,1')->name('sso.establish');

        Route::post('/step-up', [IdentityProtectionController::class, 'stepUp'])
            ->middleware('throttle:20,1')->name('step-up');

        Route::get('/sessions', [IdentitySessionController::class, 'index'])->name('sessions.index');
        Route::patch('/sessions/{sessionId}', [IdentitySessionController::class, 'rename'])
            ->name('sessions.rename');
        Route::delete('/sessions/{sessionId}', [IdentitySessionController::class, 'destroy'])->name('sessions.destroy');
        Route::delete('/sessions', [IdentitySessionController::class, 'destroyAll'])
            ->middleware('identity.step-up:security.sessions-all')->name('sessions.destroy-all');
        Route::post('/sessions/revoke-others', [IdentitySessionController::class, 'destroyOthers'])
            ->middleware('identity.step-up:security.sessions-others')->name('sessions.revoke-others');
        Route::get('/activity', [IdentityAuditController::class, 'index'])
            ->middleware('throttle:60,1')->name('activity.index');

        Route::get('/trusted-devices', [IdentityProtectionController::class, 'trustedDevices'])
            ->name('trusted-devices.index');
        Route::post('/trusted-devices', [IdentityProtectionController::class, 'trustCurrentDevice'])
            ->middleware(['identity.step-up:security.trust-device', 'throttle:10,1'])
            ->name('trusted-devices.store');
        Route::delete('/trusted-devices/{deviceId}', [IdentityProtectionController::class, 'revokeTrustedDevice'])
            ->middleware(['identity.step-up:security.trust-device', 'throttle:10,1'])
            ->name('trusted-devices.destroy');

        Route::get('/security', [IdentitySecurityController::class, 'show'])->name('security.show');
        Route::post('/two-factor/setup', [IdentitySecurityController::class, 'beginTwoFactor'])
            ->middleware(['identity.step-up:security.two-factor', 'throttle:10,1'])->name('two-factor.setup');
        Route::post('/two-factor/confirm', [IdentitySecurityController::class, 'confirmTwoFactor'])
            ->middleware('throttle:20,1')->name('two-factor.confirm');
        Route::delete('/two-factor', [IdentitySecurityController::class, 'disableTwoFactor'])
            ->middleware(['identity.step-up:security.two-factor', 'throttle:10,1'])->name('two-factor.disable');
        Route::post('/two-factor/recovery-codes', [IdentityProtectionController::class, 'regenerateRecoveryCodes'])
            ->middleware(['identity.step-up:security.recovery-codes', 'throttle:10,1'])
            ->name('two-factor.recovery-codes');

        Route::post('/passkeys/registration-options', [IdentityPasskeyController::class, 'registrationOptions'])
            ->middleware(['identity.step-up:security.passkeys', 'throttle:20,1'])->name('passkeys.registration-options');
        Route::post('/passkeys', [IdentityPasskeyController::class, 'register'])
            ->middleware('throttle:20,1')->name('passkeys.store');
        Route::delete('/passkeys/{credentialId}', [IdentityPasskeyController::class, 'destroy'])
            ->middleware('identity.step-up:security.passkeys')
            ->whereNumber('credentialId')->name('passkeys.destroy');

        Route::post('/contact/email/request-change', [IdentityContactController::class, 'requestEmailChange'])
            ->middleware(['identity.step-up:security.email-change', 'throttle:10,1'])
            ->name('contact.email.request');
        Route::post('/contact/phone/request-verification', [IdentityContactController::class, 'requestPhoneVerification'])
            ->middleware(['identity.step-up:security.phone-change', 'throttle:10,1'])
            ->name('contact.phone.request');
        Route::post('/contact/phone/confirm', [IdentityContactController::class, 'confirmPhoneVerification'])
            ->middleware('throttle:20,1')->name('contact.phone.confirm');

        Route::get('/account-merge/candidates', [IdentityAccountMergeController::class, 'candidates'])
            ->name('account-merge.candidates');
        Route::post('/account-merge/begin', [IdentityAccountMergeController::class, 'begin'])
            ->middleware(['identity.step-up:security.account-merge', 'throttle:10,1'])
            ->name('account-merge.begin');
    });
});

// Existing clients keep their URLs and response envelope while authentication
// remains owned by Identity. This prevents legacy routes from bypassing 2FA,
// centralized sessions, risk policies or account linking rules.
Route::post('auth/login', [IdentityCompatibilityController::class, 'login'])
    ->middleware('throttle:30,1')->name('identity.compat.login');
Route::post('auth/google', [IdentityCompatibilityController::class, 'google'])
    ->middleware('throttle:20,1')->name('identity.compat.google');
