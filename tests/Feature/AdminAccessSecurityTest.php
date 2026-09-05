<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminAccess;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class AdminAccessSecurityTest extends TestCase
{
    public function test_every_central_admin_route_inherits_admin_access_firewall(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);

            $this->assertContains(
                EnsureAdminAccess::class,
                $middleware,
                sprintf('A rota %s %s não herdou o firewall administrativo.', implode('|', $route->methods()), $route->uri())
            );
        }
    }

    public function test_regular_authenticated_user_is_denied(): void
    {
        $response = $this->middlewareResponse($this->user('usuario@exemplo.com', 'Participante'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('ADMIN_ACCESS_DENIED', $response->getContent());
    }

    public function test_primary_admin_email_is_allowed(): void
    {
        $response = $this->middlewareResponse($this->user('PETERTECNET@GMAIL.COM', 'Participante'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_super_admin_profile_is_allowed(): void
    {
        $response = $this->middlewareResponse($this->user('outro-admin@exemplo.com', 'Super Admin'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $response = $this->middlewareResponse(null);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('ADMIN_AUTH_REQUIRED', $response->getContent());
    }

    public function test_non_admin_api_route_is_not_blocked_by_firewall(): void
    {
        $request = Request::create('/api/applications', 'GET');

        $response = (new EnsureAdminAccess())->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private function middlewareResponse(?User $user)
    {
        $guard = Mockery::mock();
        $guard->shouldReceive('user')->once()->andReturn($user);
        Auth::shouldReceive('guard')->once()->with('api')->andReturn($guard);

        $request = Request::create('/api/admin/ecosystem/dashboard', 'GET');

        return (new EnsureAdminAccess())->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );
    }

    private function user(string $email, string $profileName): User
    {
        $user = new User([
            'email' => $email,
            'first_name' => 'Teste',
            'last_name' => 'Admin',
        ]);
        $user->id = 999;
        $user->setRelation('profile', new Profile(['name' => $profileName]));

        return $user;
    }
}
