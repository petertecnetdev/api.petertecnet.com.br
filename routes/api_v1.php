<?php

use App\Domain\Analytics\Http\Controllers\AppointmentDashboardController;
use App\Domain\Commerce\Http\Controllers\OrderingController;
use App\Domain\Commerce\Http\Controllers\OrderingSettingsController;
use App\Domain\Commerce\Http\Controllers\PaymentStatusController;
use App\Domain\Scheduling\Http\Controllers\AppointmentWorkflowController;
use App\Domain\Scheduling\Http\Controllers\AvailabilityController;
use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\EmployerController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\AppNotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
        Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog']);
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/establishments/{slug}/ordering', [OrderingController::class, 'ordering']);
        Route::post('/payments/mercadopago/webhook', [OrderingController::class, 'paymentWebhook'])->middleware('throttle:120,1');

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/me', [AccountContextController::class, 'show']);
            Route::get('/me/establishments', [EstablishmentController::class, 'mine']);
            Route::post('/establishments', [EstablishmentController::class, 'store']);
            Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);
            Route::delete('/establishments/{establishment}', [EstablishmentController::class, 'destroy']);
            Route::get('/establishments/{establishment}/metrics', [MetricsController::class, 'establishment']);

            Route::get('/establishments/{establishment}/items', [ItemController::class, 'mine']);
            Route::post('/items', [ItemController::class, 'store']);
            Route::patch('/items/{item}', [ItemController::class, 'update']);
            Route::delete('/items/{item}', [ItemController::class, 'destroy']);
            Route::get('/items/{item}/metrics', [MetricsController::class, 'item']);

            Route::get('/establishments/{establishment}/employers', [EmployerController::class, 'index']);
            Route::post('/employers', [EmployerController::class, 'store']);
            Route::delete('/employers/{employer}', [EmployerController::class, 'destroy']);
            Route::get('/employers/{employer}/items', [EmployerController::class, 'items']);
            Route::put('/employers/{employer}/items', [EmployerController::class, 'syncItems']);
            Route::get('/employers/{employer}/metrics', [EmployerController::class, 'metrics']);

            // Shared commerce capabilities.
            Route::post('/orders', [OrderingController::class, 'checkout'])->middleware('throttle:30,1');
            Route::get('/me/orders', [OrderingController::class, 'myOrders']);
            Route::get('/me/orders/{order}', [OrderingController::class, 'myOrder'])->whereNumber('order');
            Route::get('/me/orders/{order}/payment', [PaymentStatusController::class, 'show'])->whereNumber('order');
            Route::get('/establishments/{establishment}/orders', [OrderingController::class, 'establishmentOrders'])->whereNumber('establishment');
            Route::patch('/orders/{order}/status', [OrderingController::class, 'updateStatus'])->whereNumber('order');
            Route::get('/dashboard', [OrderingController::class, 'dashboard']);
            Route::get('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'show'])->whereNumber('establishment');
            Route::patch('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'update'])->whereNumber('establishment');

            // Shared scheduling capabilities. Any Peter application can adopt them
            // without introducing a product-specific backend controller.
            Route::post('/availability/times', [AvailabilityController::class, 'times']);
            Route::post('/availability/dates', [AvailabilityController::class, 'dates']);
            Route::get('/appointments/professional', [AppointmentWorkflowController::class, 'employerOrders']);
            Route::get('/appointments/{id}', [AppointmentWorkflowController::class, 'orderDetail'])->whereNumber('id');
            Route::get('/establishments/{slug}/appointments', [AppointmentWorkflowController::class, 'establishmentOrders']);
            Route::patch('/appointments/{id}/transition', [AppointmentWorkflowController::class, 'transition'])->whereNumber('id');
            Route::patch('/appointments/{id}/assign', [AppointmentWorkflowController::class, 'assign'])->whereNumber('id');
            Route::get('/users/{userName}/scheduling-profile', [AppointmentWorkflowController::class, 'userProfile']);
            Route::get('/establishments/{slug}/appointment-dashboard', [AppointmentDashboardController::class, 'overview']);

            // Shared application-scoped notifications.
            Route::get('/notifications', [AppNotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
            Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllRead']);
            Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'markRead'])->whereNumber('id');
        });
    });
