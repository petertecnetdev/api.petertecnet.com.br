<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ApplicationBrandingController as AdminApplicationBrandingController;
use App\Http\Controllers\ApplicationBrandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApplicationBrandingRoutesTest extends TestCase
{
    /** @dataProvider brandingRoutes */
    public function test_branding_routes_are_registered(
        string $method,
        string $uri,
        string $controllerMethod,
        bool $requiresAuth
    ): void {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $controller = str_starts_with($uri, '/api/admin/')
            ? AdminApplicationBrandingController::class
            : ApplicationBrandingController::class;

        $this->assertSame($controller . '@' . $controllerMethod, $route->getActionName());

        if ($requiresAuth) {
            $this->assertContains('auth:api', $route->gatherMiddleware());
        } else {
            $this->assertNotContains('auth:api', $route->gatherMiddleware());
        }
    }

    public static function brandingRoutes(): array
    {
        return [
            ['GET', '/api/applications/nexus/branding', 'show', false],
            ['GET', '/api/admin/applications/1/branding', 'show', true],
            ['PUT', '/api/admin/applications/1/branding/draft', 'updateDraft', true],
            ['DELETE', '/api/admin/applications/1/branding/draft', 'discardDraft', true],
            ['POST', '/api/admin/applications/1/branding/assets', 'uploadAsset', true],
            ['POST', '/api/admin/applications/1/branding/publish', 'publish', true],
            ['POST', '/api/admin/applications/1/branding/history/2/restore', 'restore', true],
        ];
    }
}
