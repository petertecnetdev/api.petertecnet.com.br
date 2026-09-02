<?php

use App\Domain\Scheduling\Http\Controllers\AppointmentWorkflowController;
use App\Domain\Scheduling\Http\Controllers\AvailabilityController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\RasoioDashboardController;
use App\Http\Controllers\RasoioEmployerController;
use Illuminate\Support\Facades\Route;

// Compatibility routes for the current frontend. Application context is bound
// by RouteServiceProvider; reusable scheduling logic lives under Domain/Scheduling.
Route::prefix('employer')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/available-times', [AvailabilityController::class, 'times'])
        ->name('rasoio.employer.availableTimes');

    Route::post('/store', [RasoioEmployerController::class, 'store'])
        ->name('rasoio.employer.store');
});

Route::prefix('rasoio')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/employers', [RasoioEmployerController::class, 'store']);

    Route::get('/orders/employer', [AppointmentWorkflowController::class, 'employerOrders']);
    Route::get('/orders/{id}', [AppointmentWorkflowController::class, 'orderDetail'])->whereNumber('id');
    Route::get('/establishments/{slug}/orders', [AppointmentWorkflowController::class, 'establishmentOrders']);
    Route::get('/establishments/{slug}/overview', [RasoioDashboardController::class, 'overview']);
    Route::patch('/orders/{id}/transition', [AppointmentWorkflowController::class, 'transition'])->whereNumber('id');
    Route::patch('/orders/{id}/assign', [AppointmentWorkflowController::class, 'assign'])->whereNumber('id');
    Route::get('/users/{userName}', [AppointmentWorkflowController::class, 'userProfile']);

    Route::post('/availability/times', [AvailabilityController::class, 'times']);
    Route::post('/availability/dates', [AvailabilityController::class, 'dates']);

    Route::get('/notifications', [AppNotificationController::class, 'rasoioIndex']);
    Route::get('/notifications/unread-count', [AppNotificationController::class, 'rasoioUnreadCount']);
    Route::patch('/notifications/read-all', [AppNotificationController::class, 'rasoioMarkAllRead']);
    Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'rasoioMarkRead'])->whereNumber('id');
});
