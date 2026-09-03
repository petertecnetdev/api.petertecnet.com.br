<?php

use App\Http\Controllers\Identity\IdentityExperienceController;
use App\Http\Controllers\Identity\IdentityGlobalSsoController;
use App\Http\Controllers\Identity\IdentitySessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/identity')->name('identity.experience.')->group(function () {
    Route::get('/protocol', [IdentityExperienceController::class, 'protocol'])
        ->middleware('throttle:60,1')->name('protocol');

    Route::middleware(['auth:api', 'token.version'])->group(function () {
        Route::get('/step-up/options', [IdentityExperienceController::class, 'stepUpOptions'])
            ->middleware('throttle:60,1')->name('step-up.options');
        Route::post('/step-up/password', [IdentityExperienceController::class, 'stepUpPassword'])
            ->middleware('throttle:20,1')->name('step-up.password');
        Route::post('/step-up/totp', [IdentityExperienceController::class, 'stepUpTotp'])
            ->middleware('throttle:20,1')->name('step-up.totp');
        Route::post('/step-up/passkey', [IdentityExperienceController::class, 'stepUpPasskey'])
            ->middleware('throttle:20,1')->name('step-up.passkey');

        Route::get('/devices', [IdentityExperienceController::class, 'devices'])
            ->name('devices.index');
        Route::patch('/devices/{deviceId}', [IdentityExperienceController::class, 'renameDevice'])
            ->name('devices.rename');
        Route::post('/devices/{deviceId}/trust', [IdentityExperienceController::class, 'trustDevice'])
            ->middleware('identity.step-up:trust_device')->name('devices.trust');
        Route::delete('/devices/{deviceId}', [IdentityExperienceController::class, 'revokeDevice'])
            ->middleware('identity.step-up:revoke_device')->name('devices.destroy');

        Route::get('/operations/observability', [IdentityExperienceController::class, 'observability'])
            ->middleware('throttle:60,1')->name('operations.observability');
        Route::get('/operations/rollout', [IdentityExperienceController::class, 'rollout'])
            ->name('operations.rollout');
        Route::put('/operations/rollout', [IdentityExperienceController::class, 'updateRollout'])
            ->middleware('identity.step-up:identity_rollout')->name('operations.rollout.update');

        Route::post('/password/change', [IdentityExperienceController::class, 'changePassword'])
            ->middleware(['identity.step-up:change_password', 'throttle:10,1'])->name('password.change');

        // Later aliases intentionally override the initial route definitions with
        // stronger production policy while preserving their public paths.
        Route::delete('/sessions', [IdentitySessionController::class, 'destroyAll'])
            ->middleware('identity.step-up:revoke_all_sessions')->name('sessions.destroy-all');
        Route::post('/logout-everywhere', [IdentityGlobalSsoController::class, 'logoutEverywhere'])
            ->middleware(['identity.step-up:logout_everywhere', 'throttle:10,1'])->name('logout-everywhere');
    });
});
