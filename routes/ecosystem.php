<?php

use App\Http\Controllers\Admin\EcosystemController;
use App\Http\Controllers\Admin\MarketingController;
use Illuminate\Support\Facades\Route;

Route::get('/ecosystem/site', [EcosystemController::class, 'publicSite']);

Route::prefix('admin/ecosystem')->middleware(['auth:api'])->group(function () {
    Route::get('/dashboard', [EcosystemController::class, 'dashboard']);
    Route::get('/activity', [EcosystemController::class, 'activity']);

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
