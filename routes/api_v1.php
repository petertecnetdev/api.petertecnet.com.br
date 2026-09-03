<?php

use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\CommerceController;
use App\Http\Controllers\Api\V1\EmployerController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlatOrderController;
use App\Http\Controllers\Api\V1\PlatOrderingSettingsController;
use App\Http\Controllers\Api\V1\PlatPaymentController;
use App\Http\Controllers\Api\V1\SchedulingAvailabilityController;
use App\Http\Controllers\Api\V1\SchedulingCatalogController;
use App\Http\Controllers\Api\V1\SchedulingDashboardController;
use App\Http\Controllers\Api\V1\SchedulingResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
        Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog']);
        Route::get('/items', [ItemController::class, 'index']);

        Route::get('/scheduling/catalog/business-categories', [SchedulingCatalogController::class, 'businessCategories']);
        Route::get('/scheduling/catalog/resource-types', [SchedulingCatalogController::class, 'resourceTypes']);

        // Generic commerce capabilities. These routes are intentionally app-agnostic;
        // the {application} context decides which platform owns each request.
        Route::get('/commerce/catalog/{slug}', [CommerceController::class, 'catalog']);

        // Keep the original Mercado Pago endpoint for backward compatibility while
        // new payment providers use the generic provider-scoped webhook contract.
        Route::post('/commerce/payments/mercadopago/webhook', [CommerceController::class, 'mercadoPagoWebhook'])
            ->middleware('throttle:120,1');
        Route::post('/commerce/payments/{provider}/webhook', [CommerceController::class, 'paymentWebhook'])
            ->where('provider', '[a-z0-9_-]+')
            ->middleware('throttle:120,1');

        // Legacy Plat routes are kept during the multi-platform migration so the
        // existing application keeps working while clients move to /commerce/*.
        Route::get('/establishments/{slug}/ordering', [PlatOrderController::class, 'ordering']);
        Route::post('/payments/mercadopago/webhook', [PlatOrderController::class, 'mercadoPagoWebhook'])
            ->middleware('throttle:120,1');

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

            // Generic scheduling capabilities shared by every application.
            Route::get('/scheduling/establishments/{establishment}/resources', [SchedulingResourceController::class, 'index'])
                ->whereNumber('establishment');
            Route::post('/scheduling/resources', [SchedulingResourceController::class, 'store']);
            Route::patch('/scheduling/resources/{resource}', [SchedulingResourceController::class, 'update'])->whereNumber('resource');
            Route::delete('/scheduling/resources/{resource}', [SchedulingResourceController::class, 'destroy'])->whereNumber('resource');
            Route::get('/scheduling/resources/{resource}/schedules', [SchedulingResourceController::class, 'schedules'])->whereNumber('resource');
            Route::put('/scheduling/resources/{resource}/schedules', [SchedulingResourceController::class, 'syncSchedules'])->whereNumber('resource');

            Route::get('/scheduling/availability/times', [SchedulingAvailabilityController::class, 'times']);
            Route::get('/scheduling/availability/dates', [SchedulingAvailabilityController::class, 'dates']);

            Route::post('/scheduling/appointments', [AppointmentController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/scheduling/appointments/mine', [AppointmentController::class, 'mine']);
            Route::get('/scheduling/appointments/provider', [AppointmentController::class, 'provider']);
            Route::get('/scheduling/establishments/{establishment}/appointments', [AppointmentController::class, 'establishment'])
                ->whereNumber('establishment');
            Route::get('/scheduling/appointments/{appointment}', [AppointmentController::class, 'show'])->whereNumber('appointment');
            Route::patch('/scheduling/appointments/{appointment}/transition', [AppointmentController::class, 'transition'])->whereNumber('appointment');
            Route::patch('/scheduling/appointments/{appointment}/assignment', [AppointmentController::class, 'assign'])->whereNumber('appointment');
            Route::get('/scheduling/establishments/{establishment}/dashboard', [SchedulingDashboardController::class, 'overview'])
                ->whereNumber('establishment');

            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereNumber('notification');

            Route::post('/commerce/orders', [CommerceController::class, 'checkout'])->middleware('throttle:30,1');
            Route::get('/commerce/orders/mine', [CommerceController::class, 'myOrders']);
            Route::get('/commerce/orders/{publicId}', [CommerceController::class, 'show']);
            Route::get('/commerce/orders/{publicId}/payment', [CommerceController::class, 'payment']);
            Route::post('/commerce/orders/{publicId}/payment', [CommerceController::class, 'retryPayment'])
                ->middleware('throttle:20,1');
            Route::get('/commerce/establishments/{establishment}/orders', [CommerceController::class, 'establishmentOrders'])
                ->whereNumber('establishment');
            Route::patch('/commerce/orders/{publicId}/status', [CommerceController::class, 'updateStatus']);

            // Send fulfillment bearer credentials in the request body so they do not
            // leak through query strings, reverse-proxy access logs or referrers.
            Route::post('/commerce/orders/{publicId}/fulfillment/verify', [CommerceController::class, 'verifyFulfillment'])
                ->middleware('throttle:60,1');

            // Temporary compatibility route for clients released before the body-based
            // verification endpoint. New clients must use POST /fulfillment/verify.
            Route::get('/commerce/orders/{publicId}/fulfillment', [CommerceController::class, 'verifyFulfillment'])
                ->middleware('throttle:60,1');
            Route::post('/commerce/orders/{publicId}/redeem', [CommerceController::class, 'redeem'])
                ->middleware('throttle:30,1');

            Route::post('/orders', [PlatOrderController::class, 'checkout'])->middleware('throttle:30,1');
            Route::get('/me/orders', [PlatOrderController::class, 'myOrders']);
            Route::get('/me/orders/{order}', [PlatOrderController::class, 'myOrder'])->whereNumber('order');
            Route::get('/me/orders/{order}/payment', [PlatPaymentController::class, 'show'])->whereNumber('order');
            Route::get('/establishments/{establishment}/orders', [PlatOrderController::class, 'establishmentOrders'])
                ->whereNumber('establishment');
            Route::patch('/orders/{order}/status', [PlatOrderController::class, 'updateStatus'])->whereNumber('order');
            Route::get('/dashboard', [PlatOrderController::class, 'dashboard']);

            Route::get('/establishments/{establishment}/ordering-settings', [PlatOrderingSettingsController::class, 'show'])
                ->whereNumber('establishment');
            Route::patch('/establishments/{establishment}/ordering-settings', [PlatOrderingSettingsController::class, 'update'])
                ->whereNumber('establishment');
        });
    });
