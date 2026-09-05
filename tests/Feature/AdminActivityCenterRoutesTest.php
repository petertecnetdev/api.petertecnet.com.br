<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ApiLogInsightsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminActivityCenterRoutesTest extends TestCase
{
    /** @dataProvider activityRoutes */
    public function test_activity_center_routes_are_registered(string $method, string $uri, string $controllerMethod): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(ActivityController::class . '@' . $controllerMethod, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }

    public function test_api_log_insights_route_is_registered_and_protected(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/admin/ecosystem/log-insights?range=24h', 'GET'));

        $this->assertSame(ApiLogInsightsController::class, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }

    public static function activityRoutes(): array
    {
        return [
            ['GET', '/api/admin/ecosystem/activities', 'index'],
            ['GET', '/api/admin/ecosystem/activities/overview', 'overview'],
            ['GET', '/api/admin/ecosystem/activities/facets', 'facets'],
            ['GET', '/api/admin/ecosystem/activities/123', 'show'],
        ];
    }
}
