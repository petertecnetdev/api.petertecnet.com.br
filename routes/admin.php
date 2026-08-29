<?php

use App\Http\Controllers\Admin\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:api', 'token.version', \App\Http\Middleware\EnsureCentralAdmin::class])->group(function () {
    Route::get('/dashboard', [SystemController::class, 'dashboard']);
    Route::get('/users', [SystemController::class, 'users']);
    Route::post('/users', [SystemController::class, 'storeUser']);
    Route::put('/users/{user}', [SystemController::class, 'updateUser'])->whereNumber('user');
    Route::delete('/users/{user}', [SystemController::class, 'destroyUser'])->whereNumber('user');
    Route::patch('/users/{user}/status', [SystemController::class, 'setUserStatus'])->whereNumber('user');
    Route::get('/profiles', [SystemController::class, 'profiles']);
    Route::get('/users/{user}/applications', [SystemController::class, 'applicationsAccess'])->whereNumber('user');
    Route::put('/users/{user}/applications/{application}', [SystemController::class, 'setApplicationAccess'])->whereNumber(['user','application']);
    Route::get('/audit-logs', [SystemController::class, 'auditLogs']);
});
