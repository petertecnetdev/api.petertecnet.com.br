<?php

namespace Tests\Feature;

use App\Domain\Commerce\Http\Controllers\OrderingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OrderingPaymentWebhookRouteTest extends TestCase
{
    /** @dataProvider webhookRoutes */
    public function test_ordering_payment_webhook_routes_remain_available(string $uri): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, 'POST'));

        $this->assertSame(OrderingController::class . '@paymentWebhook', $route->getActionName());
        $this->assertContains('app.context', $route->gatherMiddleware());
        $this->assertContains('app.capability:commerce', $route->gatherMiddleware());
    }

    public static function webhookRoutes(): array
    {
        return [
            'canonical ordering callback' => ['/api/v1/apps/nexus/ordering/payments/mercadopago/webhook'],
            'issued payment compatibility callback' => ['/api/v1/apps/nexus/payments/mercadopago/webhook'],
        ];
    }
}
