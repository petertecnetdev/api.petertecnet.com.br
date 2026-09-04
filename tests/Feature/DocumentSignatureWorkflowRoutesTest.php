<?php

namespace Tests\Feature;

use App\Domain\Documents\Http\Controllers\PublicSignatureController;
use App\Domain\Leasing\Http\Controllers\LeaseDocumentWorkflowController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DocumentSignatureWorkflowRoutesTest extends TestCase
{
    /** @dataProvider protectedRoutes */
    public function test_leasing_document_routes_use_generic_workflow(string $method, string $uri, string $action): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));
        $this->assertSame(LeaseDocumentWorkflowController::class.'@'.$action, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
        $this->assertContains('app.capability:leasing', $route->gatherMiddleware());
    }

    public function test_public_signature_route_is_token_scoped_and_not_authenticated(): void
    {
        $token = str_repeat('a', 64);
        $route = Route::getRoutes()->match(Request::create('/api/v1/document-signatures/'.$token, 'GET'));
        $this->assertSame(PublicSignatureController::class.'@show', $route->getActionName());
        $this->assertNotContains('auth:api', $route->gatherMiddleware());

        $signRoute = Route::getRoutes()->match(Request::create('/api/v1/document-signatures/'.$token.'/sign', 'POST'));
        $this->assertSame(PublicSignatureController::class.'@sign', $signRoute->getActionName());
        $this->assertNotContains('auth:api', $signRoute->gatherMiddleware());
        $this->assertContains('throttle:10,1', $signRoute->gatherMiddleware());
    }

    public static function protectedRoutes(): array
    {
        return [
            ['GET', '/api/v1/apps/locaio/leases/1/contract', 'show'],
            ['POST', '/api/v1/apps/locaio/leases/1/proposal/generate', 'proposal'],
            ['POST', '/api/v1/apps/locaio/leases/1/contract/generate', 'generate'],
            ['POST', '/api/v1/apps/locaio/leases/1/contract/send', 'send'],
            ['POST', '/api/v1/apps/locaio/leases/1/contract/sign', 'sign'],
            ['POST', '/api/v1/apps/locaio/leases/1/contract/amendments', 'storeAmendment'],
        ];
    }
}
