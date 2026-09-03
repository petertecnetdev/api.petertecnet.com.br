<?php

use App\Http\Controllers\InvitationActivationController;
use App\Http\Controllers\Admin\CommandCenterController;
use App\Http\Controllers\Admin\EcosystemController;
use App\Http\Controllers\Admin\FinancialController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OperationalRealtimeController;
use App\Http\Controllers\Admin\ResourceVisibilityController;
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
    Route::get('/visibility', [ResourceVisibilityController::class, 'index']);
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:20,1');

    Route::prefix('command')->group(function () {
        Route::get('/overview', [CommandCenterController::class, 'overview']);
        Route::get('/realtime-config', OperationalRealtimeController::class);
        Route::get('/search', [CommandCenterController::class, 'globalSearch']);
        Route::get('/security', [CommandCenterController::class, 'security']);
        Route::get('/queues', [CommandCenterController::class, 'queues']);
        Route::post('/queues/{uuid}/retry', [CommandCenterController::class, 'retryJob']);
        Route::get('/applications/{application}', [CommandCenterController::class, 'application'])->whereNumber('application');
        Route::get('/incidents', [CommandCenterController::class, 'incidents']);
        Route::post('/incidents', [CommandCenterController::class, 'storeIncident']);
        Route::patch('/incidents/{incident}', [CommandCenterController::class, 'updateIncident'])->whereNumber('incident');
        Route::get('/issues', [CommandCenterController::class, 'issues']);
        Route::get('/issues/{issue}', [CommandCenterController::class, 'issue'])->whereNumber('issue');
        Route::patch('/issues/{issue}', [CommandCenterController::class, 'updateIssue'])->whereNumber('issue');
        Route::post('/issues/{issue}/incident', [CommandCenterController::class, 'createIssueIncident'])->whereNumber('issue');
        Route::get('/issues/{issue}/intelligence', [CommandCenterController::class, 'issueIntelligence'])->whereNumber('issue');
        Route::post('/issues/{issue}/repair-plan', [CommandCenterController::class, 'repairPlan'])->whereNumber('issue');
        Route::get('/intelligence', [CommandCenterController::class, 'intelligence']);
    });

    Route::prefix('financial')->group(function () {
        Route::get('/dashboard', [FinancialController::class, 'dashboard']);
        Route::get('/transactions', [FinancialController::class, 'transactions']);
        Route::get('/transactions/{payment}', [FinancialController::class, 'transaction'])->whereNumber('payment');
        Route::get('/orders', [FinancialController::class, 'orders']);
        Route::get('/payouts', [FinancialController::class, 'payouts']);
        Route::get('/health', [FinancialController::class, 'health']);
        Route::get('/ledger', [FinancialController::class, 'ledger']);
        Route::get('/reconciliations', [FinancialController::class, 'reconciliations']);
        Route::post('/reconcile', [FinancialController::class, 'reconcileNow'])->middleware('throttle:10,1');
        Route::get('/closing', [FinancialController::class, 'closing']);
        Route::get('/reports/{format}', [FinancialController::class, 'export'])->whereIn('format', ['csv', 'pdf']);
    });

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
