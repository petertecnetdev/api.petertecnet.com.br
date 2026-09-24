<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class EnsureIdempotentRequestApplicationScopeTest extends TestCase
{
    public function test_it_uses_resolved_application_slug_for_idempotency_scope(): void
    {
        $request = Request::create('/api/orders', 'POST');
        $request->attributes->set('application', (object) [
            'id' => 42,
            'slug' => 'Rasoio',
        ]);
        $request->attributes->set('application_slug', 'rasoio');

        $this->assertSame('rasoio', $this->applicationKey($request));
    }

    public function test_different_resolved_applications_do_not_share_the_same_scope(): void
    {
        $rasoio = Request::create('/api/orders', 'POST');
        $rasoio->attributes->set('application_slug', 'rasoio');

        $nexus = Request::create('/api/orders', 'POST');
        $nexus->attributes->set('application_slug', 'nexus');

        $this->assertSame('rasoio', $this->applicationKey($rasoio));
        $this->assertSame('nexus', $this->applicationKey($nexus));
        $this->assertNotSame($this->applicationKey($rasoio), $this->applicationKey($nexus));
    }

    public function test_legacy_route_application_scope_remains_supported(): void
    {
        $request = Request::create('/api/apps/cutinapp/orders', 'POST');
        $request->setRouteResolver(static fn () => new class {
            public function parameter(string $key, mixed $default = null): mixed
            {
                return $key === 'application' ? 'cutinapp' : $default;
            }
        });

        $this->assertSame('cutinapp', $this->applicationKey($request));
    }

    private function applicationKey(Request $request): string
    {
        $method = new ReflectionMethod(EnsureIdempotentRequest::class, 'applicationKey');
        $method->setAccessible(true);

        return $method->invoke(new EnsureIdempotentRequest(), $request);
    }
}
