<?php

namespace Tests\Feature;

use App\Domain\Events\Http\Controllers\EventAnnouncementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EventAnnouncementRoutesTest extends TestCase
{
    /** @dataProvider protectedRoutes */
    public function test_protected_announcement_routes_are_registered(string $method, string $uri, string $controllerMethod): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(EventAnnouncementController::class.'@'.$controllerMethod, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
        $this->assertContains('token.version', $route->gatherMiddleware());
        $this->assertContains('app.context', $route->gatherMiddleware());
        $this->assertContains('app.capability:events', $route->gatherMiddleware());
    }

    public function test_public_announcement_feed_is_registered_without_authentication(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/apps/cutinapp/events/public/festa-teste/announcements', 'GET'));

        $this->assertSame(EventAnnouncementController::class.'@publicIndex', $route->getActionName());
        $this->assertContains('app.context', $route->gatherMiddleware());
        $this->assertContains('app.capability:events', $route->gatherMiddleware());
        $this->assertNotContains('auth:api', $route->gatherMiddleware());
    }

    public static function protectedRoutes(): array
    {
        return [
            ['GET', '/api/v1/apps/cutinapp/events/1/announcements/manage', 'manageIndex'],
            ['POST', '/api/v1/apps/cutinapp/events/1/announcements', 'store'],
            ['PATCH', '/api/v1/apps/cutinapp/events/1/announcements/2', 'update'],
            ['POST', '/api/v1/apps/cutinapp/events/1/announcements/2/publish', 'publish'],
            ['POST', '/api/v1/apps/cutinapp/events/1/announcements/2/unpublish', 'unpublish'],
            ['DELETE', '/api/v1/apps/cutinapp/events/1/announcements/2', 'destroy'],
        ];
    }
}
