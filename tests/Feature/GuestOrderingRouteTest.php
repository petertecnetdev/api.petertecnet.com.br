<?php

namespace Tests\Feature;

use App\Domain\Commerce\Http\Controllers\OrderingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GuestOrderingRouteTest extends TestCase
{
    public function test_guest_order_route_is_public_but_scoped_to_commerce_capability(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/apps/plat/guest-orders', 'POST'));

        $this->assertSame(OrderingController::class.'@checkout', $route->getActionName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('app.context', $middleware);
        $this->assertContains('app.capability:commerce', $middleware);
        $this->assertNotContains('auth:api', $middleware);
        $this->assertContains('throttle:6,1', $middleware);
    }

    public function test_regular_order_route_remains_authenticated(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/apps/plat/orders', 'POST'));

        $this->assertSame(OrderingController::class.'@checkout', $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }
}
