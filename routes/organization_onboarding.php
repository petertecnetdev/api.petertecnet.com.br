<?php

use App\Domain\Organizations\Http\Controllers\OrganizationOnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:organizations'])
    ->group(function () {
        Route::get('/organization-onboarding/mine', [OrganizationOnboardingController::class, 'mine']);
        Route::post('/organization-onboarding/assisted', [OrganizationOnboardingController::class, 'initiate'])
            ->middleware('throttle:20,1');
        Route::get('/organizations/{organizationId}/onboarding', [OrganizationOnboardingController::class, 'show'])
            ->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/onboarding/resend-handoff', [OrganizationOnboardingController::class, 'resendHandoff'])
            ->whereNumber('organizationId')
            ->middleware('throttle:5,1');
    });
