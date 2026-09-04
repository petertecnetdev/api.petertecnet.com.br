<?php

use App\Domain\Acquisition\Http\Controllers\AcquisitionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/acquisition/referrals/public/{token}', [AcquisitionController::class, 'publicReferral'])
            ->where('token', '[A-Za-z0-9]{40,128}')
            ->middleware('throttle:60,1');
        Route::post('/acquisition/referrals/activate', [AcquisitionController::class, 'activate'])
            ->middleware('throttle:10,1');

        Route::middleware(['auth:api', 'token.version'])->prefix('acquisition')->group(function () {
            Route::get('/context', [AcquisitionController::class, 'context']);
            Route::get('/dashboard', [AcquisitionController::class, 'dashboard']);
            Route::get('/referrals', [AcquisitionController::class, 'referrals']);
            Route::post('/onboardings', [AcquisitionController::class, 'onboard'])->middleware('throttle:20,1');
            Route::post('/referrals/{referralId}/resend', [AcquisitionController::class, 'resend'])->whereNumber('referralId')->middleware('throttle:10,1');
            Route::put('/events/{eventId}/commission', [AcquisitionController::class, 'updateCommission'])->whereNumber('eventId');
        });
    });
