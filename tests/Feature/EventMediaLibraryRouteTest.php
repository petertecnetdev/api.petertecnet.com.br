<?php

namespace Tests\Feature;

use Tests\TestCase;

final class EventMediaLibraryRouteTest extends TestCase
{
    public function test_media_library_routes_are_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'api/v1/apps/{application}/event-media'));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'api/v1/apps/{application}/event-media/{eventId}'));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'api/v1/apps/{application}/event-media/{eventId}/download'));
    }
}
