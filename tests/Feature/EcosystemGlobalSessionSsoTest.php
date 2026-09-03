<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\IdentitySession;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class EcosystemGlobalSessionSsoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.cache_store', 'array');

        $profile = Profile::create(['name' => 'Cliente', 'permissions' => []]);
        $this->user = User::create([
            'first_name' => 'Conta',
            'last_name' => 'Global',
            'email' => 'global-sso@example.test',
            'user_name' => 'global-sso-user',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'auth_version' => 1,
        ]);
        $this->token = auth('api')->login($this->user);
    }

    public function test_authenticated_app_establishes_secure_global_session_and_another_app_restores_login(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->grant($source, $destination);

        $sessionResponse = $this->establish('nexus');
        $sessionResponse->assertOk()->assertJsonPath('data.authenticated', true);

        $cookies = $this->identityCookies($sessionResponse->headers->getCookies());
        $this->assertIdentityCookieSecurity($cookies['session']);
        $this->assertIdentityCookieSecurity($cookies['refresh']);
        $this->assertDatabaseHas('identity_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('identity_sessions', [
            'session_token_hash' => $cookies['session']->getValue(),
        ]);

        $csrf = $this->csrf($cookies, 'cutinapp');
        $exchange = $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders('cutinapp') + ['X-Peter-CSRF' => $csrf])
            ->postJson('/api/identity/v1/session/exchange', ['application' => 'cutinapp']);

        $exchange->assertOk()
            ->assertJsonPath('data.application.slug', 'cutinapp')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonStructure(['data' => ['access_token', 'session' => ['id', 'device']]]);

        $rotatedRefresh = $this->cookieNamed($exchange->headers->getCookies(), 'peter_ecosystem_refresh');
        $this->assertNotNull($rotatedRefresh, 'A successful exchange must rotate the refresh secret.');
        $this->assertNotSame($cookies['refresh']->getValue(), $rotatedRefresh->getValue());
    }

    public function test_global_session_exchange_requires_csrf_bound_to_origin_and_application(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->grant($source, $destination);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders('cutinapp'))
            ->postJson('/api/identity/v1/session/exchange', ['application' => 'cutinapp'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'IDENTITY_CSRF_INVALID');

        $nexusCsrf = $this->csrf($cookies, 'nexus');
        $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders('cutinapp') + ['X-Peter-CSRF' => $nexusCsrf])
            ->postJson('/api/identity/v1/session/exchange', ['application' => 'cutinapp'])
            ->assertStatus(419);
    }

    public function test_global_session_exchange_rejects_a_different_application_origin(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->grant($source, $destination);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->getJson('/api/identity/v1/session/csrf?application=cutinapp')
            ->assertForbidden()
            ->assertJsonPath('message', 'A origem não corresponde ao aplicativo solicitado.');
    }

    public function test_global_session_exchange_rejects_a_different_application_header(): void
    {
        $application = $this->application('Nexus', 'nexus', 10);
        $this->grant($application);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->getJson('/api/identity/v1/session/csrf?application=nexus')
            ->assertForbidden()
            ->assertJsonPath('message', 'O aplicativo solicitante não corresponde à sessão requisitada.');
    }

    public function test_global_session_is_invalidated_when_auth_version_changes(): void
    {
        $destination = $this->application('Nexus', 'nexus', 10);
        $this->grant($destination);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->user->forceFill(['auth_version' => 2])->save();

        $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders('nexus'))
            ->getJson('/api/identity/v1/session/csrf?application=nexus')
            ->assertNoContent();

        $this->assertNotNull(IdentitySession::query()->first()->revoked_at);
    }

    public function test_global_logout_revokes_every_identity_session_and_existing_jwt(): void
    {
        $application = $this->application('Nexus', 'nexus', 10);
        $this->grant($application);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/identity/v1/logout', ['scope' => 'global'])
            ->assertNoContent();

        $this->assertSame(2, (int) $this->user->fresh()->auth_version);
        $this->assertDatabaseMissing('identity_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/account/context')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');
    }

    public function test_local_logout_does_not_revoke_global_identity_session(): void
    {
        $application = $this->application('Nexus', 'nexus', 10);
        $this->grant($application);
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/identity/v1/logout', ['scope' => 'current-app'])
            ->assertNoContent();

        $this->assertDatabaseHas('identity_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);
        $this->assertSame(1, (int) $this->user->fresh()->auth_version);

        $csrf = $this->csrf($cookies, 'nexus');
        $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders('nexus') + ['X-Peter-CSRF' => $csrf])
            ->postJson('/api/identity/v1/session/exchange', ['application' => 'nexus'])
            ->assertOk();
    }

    private function establish(string $slug)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'X-Peter-App' => $slug,
            'Origin' => "https://{$slug}.petertecnet.com.br",
            'X-Peter-Device' => 'de305d54-75b4-431b-adb2-eb6b9e546014',
            'X-Peter-Device-Name' => 'Chrome Windows',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36',
        ])->postJson('/api/identity/v1/session');
    }

    private function csrf(array $cookies, string $slug): string
    {
        $response = $this->withIdentityCookies($cookies)
            ->withHeaders($this->appHeaders($slug))
            ->getJson("/api/identity/v1/session/csrf?application={$slug}");

        $response->assertOk();
        return (string) $response->json('data.csrf_token');
    }

    private function withIdentityCookies(array $cookies): static
    {
        return $this
            ->withUnencryptedCookie('peter_ecosystem_session', $cookies['session']->getValue())
            ->withUnencryptedCookie('peter_ecosystem_refresh', $cookies['refresh']->getValue());
    }

    private function identityCookies(array $cookies): array
    {
        $session = $this->cookieNamed($cookies, 'peter_ecosystem_session');
        $refresh = $this->cookieNamed($cookies, 'peter_ecosystem_refresh');
        $this->assertNotNull($session);
        $this->assertNotNull($refresh);
        return compact('session', 'refresh');
    }

    private function cookieNamed(array $cookies, string $name): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) return $cookie;
        }
        return null;
    }

    private function assertIdentityCookieSecurity(Cookie $cookie): void
    {
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    private function appHeaders(string $slug): array
    {
        return [
            'X-Peter-App' => $slug,
            'Origin' => "https://{$slug}.petertecnet.com.br",
            'X-Peter-Device' => 'de305d54-75b4-431b-adb2-eb6b9e546014',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36',
        ];
    }

    private function grant(Application ...$applications): void
    {
        foreach ($applications as $application) {
            $this->user->applications()->syncWithoutDetaching([
                $application->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
            ]);
        }
    }

    private function application(string $name, string $slug, int $order): Application
    {
        return Application::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'url' => "https://{$slug}.petertecnet.com.br",
                'logo' => "https://{$slug}.petertecnet.com.br/logo.png",
                'is_active' => true,
                'is_visible' => true,
                'launcher_order' => $order,
                'operational_status' => 'operational',
                'ecosystem_sdk_version' => '2.0.0',
            ]
        );
    }
}
