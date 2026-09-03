<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CommandCenterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminCommandCenterRoutesTest extends TestCase
{
    /** @dataProvider commandRoutes */
    public function test_mission_control_routes_are_registered(string $method, string $uri, string $controllerMethod): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(CommandCenterController::class . '@' . $controllerMethod, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }

    public static function commandRoutes(): array
    {
        return [
            ['GET', '/api/admin/ecosystem/command/overview', 'overview'],
            ['GET', '/api/admin/ecosystem/command/security', 'security'],
            ['GET', '/api/admin/ecosystem/command/queues', 'queues'],
            ['GET', '/api/admin/ecosystem/command/incidents', 'incidents'],
            ['GET', '/api/admin/ecosystem/command/issues', 'issues'],
            ['GET', '/api/admin/ecosystem/command/intelligence', 'intelligence'],
            ['GET', '/api/admin/ecosystem/command/search', 'globalSearch'],
        ];
    }
}
