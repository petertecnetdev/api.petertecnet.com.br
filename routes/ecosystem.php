<?php

use App\Http\Controllers\InvitationActivationController;
use App\Http\Controllers\Admin\CommercialOperationsController;
use App\Http\Controllers\Admin\EcosystemController;
use App\Http\Controllers\Admin\EstablishmentDuplicateController;
use App\Http\Controllers\Admin\FinancialController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OnboardingSessionController;
use App\Http\Controllers\Admin\PrimaryFileController;
use Illuminate\Support\Facades\Route;

Route::get('/ecosystem/site', [EcosystemController::class, 'publicSite']);
Route::post('/auth/invite-complete', [InvitationActivationController::class, 'store'])->middleware(['api', 'throttle:10,1']);
Route::get('/auth/invitations/{token}', [InvitationActivationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40,128}')
    ->middleware(['api', 'throttle:30,1']);
Route::post('/auth/invitations/{token}/activate', [InvitationActivationController::class, 'activate'])
    ->where('token', '[A-Za-z0-9]{40,128}')
    ->middleware(['api', 'throttle:10,1']);

Route::prefix('admin/ecosystem')->middleware(['auth:api'])->group(function () {
    Route::get('/dashboard', [EcosystemController::class, 'dashboard']);
    Route::get('/activity', [EcosystemController::class, 'activity']);
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/onboarding-sessions', [OnboardingSessionController::class, 'index']);
    Route::post('/onboarding-sessions', [OnboardingSessionController::class, 'store'])->middleware('throttle:60,1');
    Route::put('/onboarding-sessions/{session}', [OnboardingSessionController::class, 'update'])->whereNumber('session');
    Route::post('/onboarding-sessions/{session}/complete', [OnboardingSessionController::class, 'complete'])->whereNumber('session');
    Route::post('/onboarding-sessions/{session}/abandon', [OnboardingSessionController::class, 'abandon'])->whereNumber('session');

    Route::post('/establishment-duplicates', EstablishmentDuplicateController::class)->middleware('throttle:120,1');
    Route::post('/files/primary', [PrimaryFileController::class, 'store'])->middleware('throttle:60,1');

    Route::prefix('operations')->group(function () {
        Route::get('/context', [CommercialOperationsController::class, 'context']);
        Route::post('/establishments', [CommercialOperationsController::class, 'storeEstablishment']);
        Route::put('/establishments/{establishment}', [CommercialOperationsController::class, 'updateEstablishment'])->whereNumber('establishment');
        Route::post('/items', [CommercialOperationsController::class, 'storeItem']);
        Route::put('/items/{item}', [CommercialOperationsController::class, 'updateItem'])->whereNumber('item');
    });

    Route::get('/financial/dashboard', [FinancialController::class, 'dashboard']);
    Route::get('/financial/transactions', [FinancialController::class, 'transactions']);
    Route::get('/financial/transactions/{payment}', [FinancialController::class, 'transaction'])->whereNumber('payment');
    Route::get('/financial/payouts', [FinancialController::class, 'payouts']);

    Route::get('/users', [EcosystemController::class, 'users']);
    Route::post('/users', [EcosystemController::class, 'storeUser']);
    Route::get('/users/{user}', [EcosystemController::class, 'userDetail'])->whereNumber('user');
    Route::put('/users/{user}', [EcosystemController::class, 'updateUser'])->whereNumber('user');
    Route::delete('/users/{user}', [EcosystemController::class, 'destroyUser'])->whereNumber('user');
    Route::put('/users/{user}/applications/{application}', [EcosystemController::class, 'setUserAccess'])->whereNumber('user')->whereNumber('application');
    Route::delete('/users/{user}/applications/{application}', [EcosystemController::class, 'removeUserAccess'])->whereNumber('user')->whereNumber('application');
    Route::get('/profiles', [EcosystemController::class, 'profiles']);
    Route::post('/profiles', [EcosystemController::class, 'storeProfile']);
    Route::put('/profiles/{profile}', [EcosystemController::class, 'updateProfile'])->whereNumber('profile');
    Route::get('/establishments', [EcosystemController::class, 'establishments']);
    Route::post('/establishments', [EcosystemController::class, 'storeEstablishment']);
    Route::put('/establishments/{establishment}', [EcosystemController::class, 'updateEstablishment'])->whereNumber('establishment');
    Route::delete('/establishments/{establishment}', [EcosystemController::class, 'destroyEstablishment'])->whereNumber('establishment');
    Route::get('/items', [EcosystemController::class, 'items']);
    Route::post('/items', [EcosystemController::class, 'storeItem']);
    Route::put('/items/{item}', [EcosystemController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/items/{item}', [EcosystemController::class, 'destroyItem'])->whereNumber('item');
    Route::get('/settings', [EcosystemController::class, 'settings']);
    Route::put('/settings', [EcosystemController::class, 'updateSettings']);
    Route::get('/audit', [EcosystemController::class, 'auditLogs']);
});

Route::prefix('admin/marketing')->middleware(['auth:api'])->group(function () {
    Route::get('/context', [MarketingController::class, 'context']);
    Route::get('/dashboard', [MarketingController::class, 'dashboard']);
    Route::get('/activity', [MarketingController::class, 'activity']);
    Route::get('/users', [MarketingController::class, 'users']);
    Route::get('/users/{user}', [MarketingController::class, 'userDetail'])->whereNumber('user');
});
