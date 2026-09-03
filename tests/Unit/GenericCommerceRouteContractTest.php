<?php

namespace Tests\Unit;

use Illuminate\Routing\Route as IlluminateRoute;
use Tests\TestCase;

class GenericCommerceRouteContractTest extends TestCase
{
    public function test_canonical_seller_orders_route_exists_without_legacy_commerce_prefix(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $this->assertTrue($this->hasRoute($routes, 'GET', 'api/v1/apps/{application}/establishments/{establishment}/orders'));
        $this->assertFalse($this->hasRoute($routes, 'GET', 'api/v1/apps/{application}/commerce/establishments/{establishment}/orders'));
    }

    public function test_generic_fulfillment_routes_are_registered_on_the_order_contract(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $expected = [
            ['GET', 'api/v1/apps/{application}/me/orders/{order}/fulfillment/credential'],
            ['PATCH', 'api/v1/apps/{application}/orders/{order}/fulfillment/status'],
            ['POST', 'api/v1/apps/{application}/orders/{order}/fulfillment/verify'],
            ['POST', 'api/v1/apps/{application}/orders/{order}/redeem'],
            ['GET', 'api/v1/apps/{application}/orders/{order}/fulfillment/events'],
        ];

        foreach ($expected as [$method, $uri]) {
            $this->assertTrue($this->hasRoute($routes, $method, $uri), "Missing {$method} {$uri}");
        }
    }

    private function hasRoute($routes, string $method, string $uri): bool
    {
        return $routes->contains(function (IlluminateRoute $route) use ($method, $uri) {
            return $route->uri() === $uri && in_array($method, $route->methods(), true);
        });
    }
}
