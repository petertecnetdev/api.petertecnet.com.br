<?php

use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\DeveloperProjectController;
use App\Http\Controllers\Api\V1\EmployerController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\OauthTokenController;
use App\Http\Controllers\Api\V1\PlatOrderController;
use App\Http\Controllers\Api\V1\PlatOrderingSettingsController;
use App\Http\Controllers\Api\V1\PlatPaymentController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('v1/oauth/token', [OauthTokenController::class, 'store'])->middleware('throttle:30,1');

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
        Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog']);
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/establishments/{slug}/ordering', [PlatOrderController::class, 'ordering']);
        Route::post('/payments/mercadopago/webhook', [PlatOrderController::class, 'mercadoPagoWebhook'])->middleware('throttle:120,1');

        // First-class developer authentication. This group is intentionally
        // additive so the existing JWT contracts used by Peter products remain intact.
        Route::middleware(['api.project', 'api.quota', 'api.usage', 'actor.context'])->prefix('platform')->group(function () {
            Route::get('/establishments', [EstablishmentController::class, 'index'])->middleware('api.scope:establishments.read');
            Route::get('/items', [ItemController::class, 'index'])->middleware('api.scope:catalog.read');
            Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog'])->middleware('api.scope:catalog.read');
        });

        Route::middleware(['auth:api', 'token.version', 'actor.context'])->group(function () {
            Route::get('/me', [AccountContextController::class, 'show']);
            Route::get('/me/establishments', [EstablishmentController::class, 'mine']);
            Route::post('/establishments', [EstablishmentController::class, 'store']);
            Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);
            Route::delete('/establishments/{establishment}', [EstablishmentController::class, 'destroy']);
            Route::get('/establishments/{establishment}/metrics', [MetricsController::class, 'establishment']);

            Route::get('/establishments/{establishment}/items', [ItemController::class, 'mine']);
            Route::post('/items', [ItemController::class, 'store']);
            Route::post('/items', [ItemController::class, 'store'])->middleware('idempotent');
            Route::patch('/items/{item}', [ItemController::class, 'update'])->middleware('idempotent');
            Route::delete('/items/{item}', [ItemController::class, 'destroy'])->middleware('idempotent');
            Route::get('/items/{item}/metrics', [MetricsController::class, 'item']);

            Route::get('/establishments/{establishment}/employers', [EmployerController::class, 'index']);
            Route::post('/employers', [EmployerController::class, 'store'])->middleware('idempotent');
            Route::delete('/employers/{employer}', [EmployerController::class, 'destroy'])->middleware('idempotent');
            Route::get('/employers/{employer}/items', [EmployerController::class, 'items']);
            Route::put('/employers/{employer}/items', [EmployerController::class, 'syncItems'])->middleware('idempotent');
            Route::get('/employers/{employer}/metrics', [EmployerController::class, 'metrics']);

            Route::post('/orders', [PlatOrderController::class, 'checkout'])->middleware(['throttle:30,1', 'idempotent']);
            Route::get('/me/orders', [PlatOrderController::class, 'myOrders']);
            Route::get('/me/orders/{order}', [PlatOrderController::class, 'myOrder'])->whereNumber('order');
            Route::get('/me/orders/{order}/payment', [PlatPaymentController::class, 'show'])->whereNumber('order');
            Route::get('/establishments/{establishment}/orders', [PlatOrderController::class, 'establishmentOrders'])->whereNumber('establishment');
            Route::patch('/orders/{order}/status', [PlatOrderController::class, 'updateStatus'])->whereNumber('order')->middleware('idempotent');
            Route::get('/dashboard', [PlatOrderController::class, 'dashboard']);

            Route::get('/establishments/{establishment}/ordering-settings', [PlatOrderingSettingsController::class, 'show'])->whereNumber('establishment');
            Route::patch('/establishments/{establishment}/ordering-settings', [PlatOrderingSettingsController::class, 'update'])->whereNumber('establishment')->middleware('idempotent');

            Route::prefix('developer')->group(function () {
                Route::get('/projects', [DeveloperProjectController::class, 'index']);
                Route::post('/projects', [DeveloperProjectController::class, 'store']);
                Route::post('/projects/{project}/api-keys', [DeveloperProjectController::class, 'issueApiKey']);
                Route::post('/projects/{project}/oauth-clients', [DeveloperProjectController::class, 'issueOauthClient']);
                Route::get('/projects/{project}/usage', [DeveloperProjectController::class, 'usage']);
                Route::get('/projects/{project}/webhooks', [WebhookController::class, 'index']);
                Route::post('/projects/{project}/webhooks', [WebhookController::class, 'store']);
                Route::patch('/projects/{project}/webhooks/{webhook}', [WebhookController::class, 'update']);
                Route::delete('/projects/{project}/webhooks/{webhook}', [WebhookController::class, 'destroy']);
            });
        });
    });
