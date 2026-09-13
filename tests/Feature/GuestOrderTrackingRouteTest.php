<?php

namespace Tests\Feature;

use App\Domain\Commerce\Http\Controllers\GuestOrderTrackingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GuestOrderTrackingRouteTest extends TestCase
{
    public function test_guest_tracking_route_is_public_scoped_and_throttled(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/apps/plat/guest-orders/track', 'POST'));

        $this->assertSame(GuestOrderTrackingController::class, $route->getActionName());
        $middleware = $route->gatherMiddleware();

        $this->assertContains('app.context', $middleware);
        $this->assertContains('app.capability:commerce', $middleware);
        $this->assertContains('throttle:6,1', $middleware);
        $this->assertNotContains('auth:api', $middleware);
    }
}
