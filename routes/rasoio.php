<?php

use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\RasoioWorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('rasoio')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/orders/employer', [RasoioWorkflowController::class, 'employerOrders']);
    Route::get('/orders/{id}', [RasoioWorkflowController::class, 'orderDetail'])->whereNumber('id');
    Route::get('/establishments/{slug}/orders', [RasoioWorkflowController::class, 'establishmentOrders']);
    Route::patch('/orders/{id}/transition', [RasoioWorkflowController::class, 'transition'])->whereNumber('id');
    Route::patch('/orders/{id}/assign', [RasoioWorkflowController::class, 'assign'])->whereNumber('id');
    Route::get('/users/{userName}', [RasoioWorkflowController::class, 'userProfile']);

    Route::get('/notifications', fn ($request) => app(AppNotificationController::class)->index($request, 1));
    Route::get('/notifications/unread-count', fn ($request) => app(AppNotificationController::class)->unreadCount($request, 1));
    Route::patch('/notifications/{id}/read', fn ($request, $id) => app(AppNotificationController::class)->markRead($request, 1, (int) $id))->whereNumber('id');
    Route::patch('/notifications/read-all', fn ($request) => app(AppNotificationController::class)->markAllRead($request, 1));
});
