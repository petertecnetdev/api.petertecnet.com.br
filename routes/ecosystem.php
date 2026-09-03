<?php

use App\Http\Controllers\InvitationActivationController;
use App\Http\Controllers\Admin\AdministrativeReportController;
use App\Http\Controllers\Admin\CommandCenterController;
use App\Http\Controllers\Admin\EcosystemController;
use App\Http\Controllers\Admin\FinancialController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OperationalDiagnosticsController;
use App\Http\Controllers\Admin\OperationalIssueController;
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
    Route::get('/reports', [AdministrativeReportController::class, 'index']);
    Route::get('/reports/{report}/pdf', [AdministrativeReportController::class, 'pdf'])->where('report', '[a-z]+');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/command/overview', [CommandCenterController::class, 'overview']);
    Route::get('/command/search', [CommandCenterController::class, 'globalSearch']);
    Route::get('/command/security', [OperationalDiagnosticsController::class, 'security']);
    Route::get('/command/issues', [OperationalIssueController::class, 'index']);
    Route::get('/command/issues/{issue}', [OperationalIssueController::class, 'show'])->whereNumber('issue');
    Route::patch('/command/issues/{issue}', [OperationalIssueController::class, 'update'])->whereNumber('issue');
    Route::post('/command/issues/{issue}/incident', [OperationalIssueController::class, 'createIncident'])->whereNumber('issue');
    Route::get('/command/queues', [CommandCenterController::class, 'queues']);
    Route::post('/command/queues/{uuid}/retry', [CommandCenterController::class, 'retryJob']);
    Route::get('/command/applications/{application}', [CommandCenterController::class, 'application'])->whereNumber('application');
    Route::get('/command/incidents', [CommandCenterController::class, 'incidents']);
    Route::post('/command/incidents', [CommandCenterController::class, 'storeIncident']);
    Route::patch('/command/incidents/{incident}', [CommandCenterController::class, 'updateIncident'])->whereNumber('incident');

    Route::prefix('financial')->middleware('admin.permission:finance_view')->group(function () {
        Route::get('/dashboard', [FinancialController::class, 'dashboard']);
        Route::get('/transactions', [FinancialController::class, 'transactions']);
        Route::get('/transactions/{payment}', [FinancialController::class, 'transaction'])->whereNumber('payment');
        Route::get('/ledger', [FinancialController::class, 'ledger']);
        Route::get('/reconciliations', [FinancialController::class, 'reconciliations']);
        Route::post('/reconcile', [FinancialController::class, 'reconcileNow'])->middleware('throttle:10,1');
        Route::get('/closing', [FinancialController::class, 'closing']);
        Route::get('/reports/{format}', [FinancialController::class, 'export'])->whereIn('format', ['csv', 'pdf']);
        Route::get('/orders', [FinancialController::class, 'orders']);
        Route::get('/payouts', [FinancialController::class, 'payouts']);
        Route::get('/health', [FinancialController::class, 'health']);
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

    Route::get('/audit', [EcosystemController::class, 'auditLogs'])->middleware('admin.permission:audit_view');
});

Route::prefix('admin/marketing')->middleware(['auth:api'])->group(function () {
    Route::get('/context', [MarketingController::class, 'context']);
    Route::get('/dashboard', [MarketingController::class, 'dashboard']);
    Route::get('/activity', [MarketingController::class, 'activity']);
    Route::get('/users', [MarketingController::class, 'users']);
    Route::get('/users/{user}', [MarketingController::class, 'userDetail'])->whereNumber('user');
});