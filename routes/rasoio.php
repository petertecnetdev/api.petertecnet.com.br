<?php

use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\RasoioAvailabilityController;
use App\Http\Controllers\RasoioDashboardController;
use App\Http\Controllers\RasoioEmployerController;
use App\Http\Controllers\RasoioWorkflowController;
use Illuminate\Support\Facades\Route;

// Mantém compatibilidade com o frontend atual e também com builds antigos da Rasoio.
// Este arquivo é carregado depois de routes/api.php, portanto estas definições
// passam a ser as regras efetivas para os endpoints legados usados pela Rasoio.
Route::prefix('employer')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/available-times', [RasoioAvailabilityController::class, 'times'])
        ->name('rasoio.employer.availableTimes');

    // Compatibilidade com versões antigas do frontend que ainda usam
    // POST /employer/store. Na Rasoio, proprietário/gerente também pode ser
    // colaborador atendente da própria empresa.
    Route::post('/store', [RasoioEmployerController::class, 'store'])
        ->name('rasoio.employer.store');
});

Route::prefix('rasoio')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/employers', [RasoioEmployerController::class, 'store']);

    Route::get('/orders/employer', [RasoioWorkflowController::class, 'employerOrders']);
    Route::get('/orders/{id}', [RasoioWorkflowController::class, 'orderDetail'])->whereNumber('id');
    Route::get('/establishments/{slug}/orders', [RasoioWorkflowController::class, 'establishmentOrders']);
    Route::get('/establishments/{slug}/overview', [RasoioDashboardController::class, 'overview']);
    Route::patch('/orders/{id}/transition', [RasoioWorkflowController::class, 'transition'])->whereNumber('id');
    Route::patch('/orders/{id}/assign', [RasoioWorkflowController::class, 'assign'])->whereNumber('id');
    Route::get('/users/{userName}', [RasoioWorkflowController::class, 'userProfile']);

    Route::post('/availability/times', [RasoioAvailabilityController::class, 'times']);
    Route::post('/availability/dates', [RasoioAvailabilityController::class, 'dates']);

    Route::get('/notifications', [AppNotificationController::class, 'rasoioIndex']);
    Route::get('/notifications/unread-count', [AppNotificationController::class, 'rasoioUnreadCount']);
    Route::patch('/notifications/read-all', [AppNotificationController::class, 'rasoioMarkAllRead']);
    Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'rasoioMarkRead'])->whereNumber('id');
});
