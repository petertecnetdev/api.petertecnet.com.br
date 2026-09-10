<?php

use App\Http\Controllers\InvitationActivationController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Admin\AdminControlPlaneController;
use App\Http\Controllers\Admin\AdminEventController;
use App\Http\Controllers\Admin\AdminUserDetailController;
use App\Http\Controllers\Admin\CommandCenterController;
use App\Http\Controllers\Admin\EcosystemController;
use App\Http\Controllers\Admin\EcosystemNotificationController;
use App\Http\Controllers\Admin\EstablishmentEventController;
use App\Http\Controllers\Admin\FinancialController;
use App\Http\Controllers\Admin\InteractionMaintenanceController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\OperationalRealtimeController;
use App\Http\Controllers\Admin\ProspectInvitationController;
use App\Http\Controllers\Admin\ResourceVisibilityController;
use App\Http\Controllers\Admin\TelemetryController;
use App\Http\Controllers\Admin\UserCommunicationController;
use Illuminate\Support\Facades\Route;

Route::get('/ecosystem/site', [EcosystemController::class, 'publicSite']);
Route::post('/auth/invite-complete', [InvitationActivationController::class, 'store'])->middleware(['api', 'throttle:10,1']);
Route::get('/auth/invitations/{token}', [InvitationActivationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40,128}')
    ->middleware(['api', 'throttle:30,1']);
Route::post('/auth/invitations/{token}/activate', [InvitationActivationController::class, 'activate'])
    ->where('token', '[A-Za-z0-9]{40,128}')
    ->middleware(['api', 'throttle:10,1']);

Route::post('/auth/impersonation/exchange', [ImpersonationController::class, 'exchange'])
    ->middleware(['api', 'throttle:20,1'])
    ->name('impersonation.exchange');

Route::prefix('auth/impersonation')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/current', [ImpersonationController::class, 'current'])->name('impersonation.current');
    Route::post('/end', [ImpersonationController::class, 'endCurrent'])->name('impersonation.end');
});

Route::prefix('admin/ecosystem')->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])->group(function () {
    Route::get('/dashboard', [EcosystemController::class, 'dashboard']);
    Route::get('/activity', [EcosystemController::class, 'activity']);
    Route::delete('/activity', [InteractionMaintenanceController::class, 'destroySelected'])->middleware('throttle:20,1');
    Route::delete('/activity/all', [InteractionMaintenanceController::class, 'destroyAll'])->middleware('throttle:3,10');
    Route::get('/notifications', [EcosystemNotificationController::class, 'index']);
    Route::post('/notifications/preview', [EcosystemNotificationController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('/notifications', [EcosystemNotificationController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/invitations/prospect', [ProspectInvitationController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/visibility', [ResourceVisibilityController::class, 'index']);
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:20,1');

    Route::prefix('telemetry')->group(function () {
        Route::get('/health', [TelemetryController::class, 'health']);
        Route::get('/journeys', [TelemetryController::class, 'journeys']);
        Route::get('/journeys/{session}', [TelemetryController::class, 'journey'])
            ->where('session', '[A-Za-z0-9._:-]{6,100}');
    });

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

    Route::prefix('control')->group(function () {
        Route::get('/capabilities', [AdminControlPlaneController::class, 'capabilities']);
        Route::get('/feature-flags', [AdminControlPlaneController::class, 'featureFlags']);
        Route::put('/feature-flags', [AdminControlPlaneController::class, 'saveFeatureFlags']);
        Route::get('/saved-views', [AdminControlPlaneController::class, 'savedViews']);
        Route::post('/saved-views', [AdminControlPlaneController::class, 'saveView']);
        Route::delete('/saved-views/{setting}', [AdminControlPlaneController::class, 'deleteView'])->whereNumber('setting');
        Route::get('/notifications', [AdminControlPlaneController::class, 'notificationCampaigns']);
        Route::post('/notifications', [AdminControlPlaneController::class, 'storeNotificationCampaign'])->middleware('throttle:10,1');
        Route::get('/moderation', [AdminControlPlaneController::class, 'moderation']);
        Route::patch('/moderation/{report}', [AdminControlPlaneController::class, 'updateModeration'])->whereNumber('report');
        Route::get('/trash', [AdminControlPlaneController::class, 'trash']);
        Route::post('/trash/{resource}/{id}/restore', [AdminControlPlaneController::class, 'restoreTrash'])->whereNumber('id');
        Route::get('/export/{resource}', [AdminControlPlaneController::class, 'export']);
        Route::post('/import/{resource}', [AdminControlPlaneController::class, 'import']);
    });

    Route::prefix('event-management')->group(function () {
        Route::get('/users', [AdminEventController::class, 'users']);
        Route::get('/users/{user}/productions', [AdminEventController::class, 'productions'])->whereNumber('user');
        Route::post('/events', [AdminEventController::class, 'store'])->middleware('throttle:30,1');
    });

    Route::post('/users/resend-email', [UserCommunicationController::class, 'resend'])->middleware('throttle:3,10');
    Route::post('/users/{user}/communications/email', [UserCommunicationController::class, 'sendMessage'])->whereNumber('user')->middleware('throttle:10,1');
    Route::get('/users', [EcosystemController::class, 'users']);
    Route::post('/users', [EcosystemController::class, 'storeUser']);
    Route::get('/users/{user}', [AdminUserDetailController::class, 'show'])->whereNumber('user');
    Route::get('/users/{user}/activity', [AdminUserDetailController::class, 'activity'])->whereNumber('user');
    Route::post('/users/{user}/notes', [AdminUserDetailController::class, 'storeNote'])->whereNumber('user')->middleware('throttle:30,1');
    Route::delete('/users/{user}/notes/{annotation}', [AdminUserDetailController::class, 'deleteNote'])->whereNumber('user')->whereNumber('annotation')->middleware('throttle:30,1');
    Route::put('/users/{user}/tags', [AdminUserDetailController::class, 'updateTags'])->whereNumber('user')->middleware('throttle:30,1');
    Route::post('/users/{user}/security/revoke-sessions', [AdminUserDetailController::class, 'revokeSessions'])->whereNumber('user')->middleware('throttle:10,1');
    Route::patch('/users/{user}/account-access', [AdminUserDetailController::class, 'accountAccess'])->whereNumber('user')->middleware('throttle:10,1');
    Route::put('/users/{user}', [EcosystemController::class, 'updateUser'])->whereNumber('user');
    Route::delete('/users/{user}', [EcosystemController::class, 'destroyUser'])->whereNumber('user');
    Route::put('/users/{user}/applications/{application}', [EcosystemController::class, 'setUserAccess'])->whereNumber('user')->whereNumber('application');
    Route::delete('/users/{user}/applications/{application}', [EcosystemController::class, 'removeUserAccess'])->whereNumber('user')->whereNumber('application');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->whereNumber('user')->middleware('throttle:20,1');
    Route::get('/impersonations', [ImpersonationController::class, 'history']);
    Route::get('/impersonations/{session}/audit', [ImpersonationController::class, 'audit'])->whereNumber('session');
    Route::post('/impersonations/{session}/end', [ImpersonationController::class, 'forceEnd'])->whereNumber('session');
    Route::get('/profiles', [EcosystemController::class, 'profiles']);
    Route::post('/profiles', [EcosystemController::class, 'storeProfile']);
    Route::put('/profiles/{profile}', [EcosystemController::class, 'updateProfile'])->whereNumber('profile');
    Route::get('/establishments', [EcosystemController::class, 'establishments']);
    Route::post('/establishments', [EcosystemController::class, 'storeEstablishment']);
    Route::get('/establishments/{establishment}/resources/events', [EstablishmentEventController::class, 'index'])->whereNumber('establishment');
    Route::get('/establishments/{establishment}/resources/events/{event}/tickets', [EstablishmentEventController::class, 'tickets'])->whereNumber('establishment')->whereNumber('event');
    Route::put('/establishments/{establishment}', [EcosystemController::class, 'updateEstablishment'])->whereNumber('establishment');
    Route::put('/establishments/{establishment}/owner', [EcosystemController::class, 'transferEstablishmentOwner'])->whereNumber('establishment');
    Route::delete('/establishments/{establishment}', [EcosystemController::class, 'destroyEstablishment'])->whereNumber('establishment');
    Route::get('/items', [EcosystemController::class, 'items']);
    Route::post('/items', [EcosystemController::class, 'storeItem']);
    Route::put('/items/{item}', [EcosystemController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/items/{item}', [EcosystemController::class, 'destroyItem'])->whereNumber('item');
    Route::get('/settings', [EcosystemController::class, 'settings']);
    Route::put('/settings', [EcosystemController::class, 'updateSettings']);
    Route::get('/audit', [EcosystemController::class, 'auditLogs']);
});

Route::prefix('admin/marketing')->middleware(['auth:api', \App\Http\Middleware\PeterTecnetAdminApi::class])->group(function () {
    Route::get('/context', [MarketingController::class, 'context']);
    Route::get('/dashboard', [MarketingController::class, 'dashboard']);
    Route::get('/activity', [MarketingController::class, 'activity']);
    Route::get('/users', [MarketingController::class, 'users']);
    Route::get('/users/{user}', [MarketingController::class, 'userDetail'])->whereNumber('user');
});