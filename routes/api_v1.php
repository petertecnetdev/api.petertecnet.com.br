<?php

use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\CheckInController;
use App\Http\Controllers\Api\V1\DeveloperProjectController;
use App\Http\Controllers\Api\V1\EmployerController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\OauthTokenController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderingSettingsController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\SandboxResourceController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('v1/oauth/token', [OauthTokenController::class, 'store'])->middleware('throttle:30,1');

Route::prefix('v1/identity')->group(function () {
    Route::post('/login', [IdentityController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/register', [IdentityController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/google', [IdentityController::class, 'google'])->middleware('throttle:10,1');
    Route::post('/refresh', [IdentityController::class, 'refresh'])->middleware('throttle:30,1');
    Route::post('/password-email', [IdentityController::class, 'requestPasswordReset'])->middleware('throttle:5,1');
    Route::post('/password-reset', [IdentityController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware(['auth:api', 'token.version', 'actor.context'])->group(function () {
        Route::post('/logout', [IdentityController::class, 'logout']);
        Route::get('/me', [IdentityController::class, 'me']);
        Route::get('/check-auth', [IdentityController::class, 'check']);
        Route::post('/email-verify', [IdentityController::class, 'verifyEmail']);
        Route::post('/change-password', [IdentityController::class, 'changePassword']);
        Route::post('/resend-code-email-verification', [IdentityController::class, 'resendVerification'])->middleware('throttle:5,1');
    });
});

Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('/login', [IdentityController::class, 'login'])->middleware('throttle:10,1');
            Route::post('/register', [IdentityController::class, 'register'])->middleware('throttle:5,1');
            Route::post('/google', [IdentityController::class, 'google'])->middleware('throttle:10,1');
            Route::post('/refresh', [IdentityController::class, 'refresh'])->middleware('throttle:30,1');
            Route::post('/password-email', [IdentityController::class, 'requestPasswordReset'])->middleware('throttle:5,1');
            Route::post('/password-reset', [IdentityController::class, 'resetPassword'])->middleware('throttle:10,1');

            Route::middleware(['auth:api', 'token.version', 'actor.context'])->group(function () {
                Route::post('/logout', [IdentityController::class, 'logout']);
                Route::get('/me', [IdentityController::class, 'me']);
                Route::get('/check-auth', [IdentityController::class, 'check']);
                Route::post('/email-verify', [IdentityController::class, 'verifyEmail']);
                Route::post('/change-password', [IdentityController::class, 'changePassword']);
                Route::post('/resend-code-email-verification', [IdentityController::class, 'resendVerification'])->middleware('throttle:5,1');
            });
        });

        // Public application-scoped discovery contracts.
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
        Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog']);
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/people', [PeopleController::class, 'index']);
        Route::get('/people/{person}', [PeopleController::class, 'show'])->whereNumber('person');
        Route::get('/teams', [TeamController::class, 'index']);
        Route::get('/teams/{team}', [TeamController::class, 'show'])->whereNumber('team');
        Route::get('/events/{event}/admissions', [AdmissionController::class, 'index'])->whereNumber('event');
        Route::get('/establishments/{slug}/ordering', [OrderController::class, 'ordering']);
        Route::post('/payments/mercadopago/webhook', [OrderController::class, 'mercadoPagoWebhook'])->middleware('throttle:120,1');

        Route::middleware(['api.project', 'api.quota', 'actor.context'])->prefix('platform')->group(function () {
            Route::middleware('api.production')->group(function () {
                Route::get('/establishments', [EstablishmentController::class, 'index'])->middleware('api.scope:establishments.read');
                Route::get('/items', [ItemController::class, 'index'])->middleware('api.scope:catalog.read');
                Route::get('/catalog/{establishmentSlug}', [ItemController::class, 'catalog'])->middleware('api.scope:catalog.read');
                Route::get('/people', [PeopleController::class, 'index'])->middleware('api.scope:people.read');
                Route::get('/teams', [TeamController::class, 'index'])->middleware('api.scope:people.read');
            });

            Route::prefix('sandbox')->group(function () {
                Route::get('/{resourceType}', [SandboxResourceController::class, 'index'])->middleware('api.scope:sandbox.read');
                Route::post('/{resourceType}', [SandboxResourceController::class, 'store'])->middleware(['api.scope:sandbox.write', 'idempotent']);
                Route::get('/{resourceType}/{publicId}', [SandboxResourceController::class, 'show'])->middleware('api.scope:sandbox.read');
                Route::patch('/{resourceType}/{publicId}', [SandboxResourceController::class, 'update'])->middleware(['api.scope:sandbox.write', 'idempotent']);
                Route::delete('/{resourceType}/{publicId}', [SandboxResourceController::class, 'destroy'])->middleware(['api.scope:sandbox.write', 'idempotent']);
            });
        });

        Route::middleware(['auth:api', 'token.version', 'actor.context'])->group(function () {
            Route::get('/me', [AccountContextController::class, 'show']);
            Route::get('/me/establishments', [EstablishmentController::class, 'mine']);
            Route::get('/me/admissions', [AdmissionController::class, 'mine']);

            Route::post('/people', [PeopleController::class, 'store'])->middleware(['actor.scope:people.write', 'idempotent']);
            Route::patch('/people/{person}', [PeopleController::class, 'update'])->whereNumber('person')->middleware(['actor.scope:people.write', 'idempotent']);
            Route::post('/teams', [TeamController::class, 'store'])->middleware(['actor.scope:people.write', 'idempotent']);
            Route::put('/teams/{team}/members', [TeamController::class, 'syncMembers'])->whereNumber('team')->middleware(['actor.scope:people.write', 'idempotent']);
            Route::post('/events/{event}/admissions', [AdmissionController::class, 'store'])->whereNumber('event')->middleware(['actor.scope:events.write', 'idempotent']);
            Route::post('/admissions/{admission}/credentials', [AdmissionController::class, 'issue'])->whereNumber('admission')->middleware(['actor.scope:events.write', 'idempotent']);
            Route::post('/check-ins', [CheckInController::class, 'store'])->middleware(['actor.scope:events.checkin', 'idempotent']);

            Route::post('/establishments', [EstablishmentController::class, 'store'])->middleware('idempotent');

            Route::middleware('tenant.context')->group(function () {
                Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update'])->middleware('idempotent');
                Route::delete('/establishments/{establishment}', [EstablishmentController::class, 'destroy'])->middleware('idempotent');
                Route::get('/establishments/{establishment}/metrics', [MetricsController::class, 'establishment']);
                Route::get('/establishments/{establishment}/items', [ItemController::class, 'mine']);
                Route::get('/establishments/{establishment}/employers', [EmployerController::class, 'index']);
                Route::get('/establishments/{establishment}/orders', [OrderController::class, 'establishmentOrders'])->whereNumber('establishment');
                Route::get('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'show'])->whereNumber('establishment');
                Route::patch('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'update'])->whereNumber('establishment')->middleware('idempotent');
            });

            Route::post('/items', [ItemController::class, 'store'])->middleware('idempotent');
            Route::patch('/items/{item}', [ItemController::class, 'update'])->middleware('idempotent');
            Route::delete('/items/{item}', [ItemController::class, 'destroy'])->middleware('idempotent');
            Route::get('/items/{item}/metrics', [MetricsController::class, 'item']);

            Route::post('/employers', [EmployerController::class, 'store'])->middleware('idempotent');
            Route::delete('/employers/{employer}', [EmployerController::class, 'destroy'])->middleware('idempotent');
            Route::get('/employers/{employer}/items', [EmployerController::class, 'items']);
            Route::put('/employers/{employer}/items', [EmployerController::class, 'syncItems'])->middleware('idempotent');
            Route::get('/employers/{employer}/metrics', [EmployerController::class, 'metrics']);

            Route::post('/orders', [OrderController::class, 'checkout'])->middleware(['throttle:30,1', 'idempotent']);
            Route::get('/me/orders', [OrderController::class, 'myOrders']);
            Route::get('/me/orders/{order}', [OrderController::class, 'myOrder']);
            Route::get('/me/orders/{order}/payment', [PaymentController::class, 'show']);
            Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('idempotent');
            Route::get('/dashboard', [OrderController::class, 'dashboard']);

            Route::prefix('developer')->group(function () {
                Route::get('/projects', [DeveloperProjectController::class, 'index']);
                Route::post('/projects', [DeveloperProjectController::class, 'store'])->middleware('idempotent');
                Route::post('/projects/{project}/api-keys', [DeveloperProjectController::class, 'issueApiKey'])->middleware('idempotent');
                Route::post('/projects/{project}/oauth-clients', [DeveloperProjectController::class, 'issueOauthClient'])->middleware('idempotent');
                Route::get('/projects/{project}/usage', [DeveloperProjectController::class, 'usage']);
                Route::get('/projects/{project}/webhooks', [WebhookController::class, 'index']);
                Route::post('/projects/{project}/webhooks', [WebhookController::class, 'store'])->middleware('idempotent');
                Route::patch('/projects/{project}/webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('idempotent');
                Route::delete('/projects/{project}/webhooks/{webhook}', [WebhookController::class, 'destroy'])->middleware('idempotent');
            });
        });
    });
