<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ApplicationOperationsController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminApplicationOperationsRouteTest extends TestCase
{
    public function test_application_operations_route_is_registered_and_admin_protected(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/admin/ecosystem/applications/{application}/operations');

        $this->assertNotNull($route);
        $this->assertSame(ApplicationOperationsController::class.'@show', $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }
}
