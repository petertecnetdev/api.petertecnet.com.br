<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class IdentityGlobalSsoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Application $source;
    private Application $destination;
    private string $legacyToken;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.global_sso.cache_store', 'array');
        config()->set('identity.global_sso.require_https_origin', true);
        config()->set('identity.password.compromised_check', false);

        $profile = Profile::query()->create(['name' => 'Usuário', 'permissions' => []]);
        $this->user = User::query()->create([
            'first_name' => 'Global',
            'email' => 'global-identity@example.test',
            'user_name' => 'global-identity',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'auth_version' => 1,
            'email_verified_at' => now(),
        ]);

        $this->source = $this->app('Nexus', 'nexus');
        $this->destination = $this->app('Cutinapp', 'cutinapp');
        $this->user->applications()->syncWithoutDetaching([
            $this->source->id => ['role' => 'member', 'status' => 'active', 'joined_at' => now()],
            $this->destination->id => ['role' => 'member', 'status' => 'active', 'joined_at' => now()],
        ]);

        $this->legacyToken = auth('api')->login($this->user);
    }

    public function test_direct_opening_another_app_restores_a_fresh_app_session_and_rotates_refresh(): void
    {
        $established = $this->establish('nexus');
        $established->assertOk()->assertJsonPath('data.authenticated', true);
        $cookies = $this->identityCookies($established->headers->getCookies());

        $this->assertSecureCookie($cookies['session']);
        $this->assertSecureCookie($cookies['refresh']);
        $this->assertDatabaseHas('identity_global_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('identity_global_sessions', [
            'session_token_hash' => $cookies['session']->getValue(),
        ]);
        $this->assertDatabaseMissing('identity_global_sessions', [
            'refresh_token_hash' => $cookies['refresh']->getValue(),
        ]);

        $csrf = $this->csrf($cookies, 'cutinapp');
        $exchange = $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers('cutinapp') + ['X-Peter-CSRF' => $csrf])
            ->postJson('/api/account/identity/sso/exchange', ['application' => 'cutinapp']);

        $exchange->assertOk()
            ->assertJsonPath('data.application.slug', 'cutinapp')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.session.application.slug', 'cutinapp')
            ->assertJsonStructure(['data' => ['access_token', 'session' => ['id'], 'global_session' => ['id']]]);

        $rotated = $this->cookieNamed($exchange->headers->getCookies(), 'peter_ecosystem_refresh');
        $this->assertNotNull($rotated);
        $this->assertNotSame($cookies['refresh']->getValue(), $rotated->getValue());
    }

    public function test_exchange_requires_csrf_bound_to_destination_application_and_origin(): void
    {
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers('cutinapp'))
            ->postJson('/api/account/identity/sso/exchange', ['application' => 'cutinapp'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'IDENTITY_CSRF_INVALID');

        $sourceCsrf = $this->csrf($cookies, 'nexus');
        $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers('cutinapp') + ['X-Peter-CSRF' => $sourceCsrf])
            ->postJson('/api/account/identity/sso/exchange', ['application' => 'cutinapp'])
            ->assertStatus(419);
    }

    public function test_origin_and_app_header_must_match_the_registered_application(): void
    {
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
                'User-Agent' => $this->userAgent(),
            ])
            ->getJson('/api/account/identity/sso/csrf?application=cutinapp')
            ->assertForbidden();

        $this->withIdentityCookies($cookies)
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
                'User-Agent' => $this->userAgent(),
            ])
            ->getJson('/api/account/identity/sso/csrf?application=nexus')
            ->assertForbidden();
    }

    public function test_network_change_is_tolerated_but_browser_platform_change_revokes_global_session(): void
    {
        $cookies = $this->identityCookies($this->establish('nexus')->headers->getCookies());

        $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers('nexus') + ['REMOTE_ADDR' => '10.0.0.25'])
            ->getJson('/api/account/identity/sso/csrf?application=nexus')
            ->assertOk();

        $this->withIdentityCookies($cookies)
            ->withHeaders([
                'X-Peter-App' => 'nexus',
                'Origin' => 'https://nexus.petertecnet.com.br',
                'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1',
            ])
            ->getJson('/api/account/identity/sso/csrf?application=nexus')
            ->assertNoContent();

        $this->assertDatabaseMissing('identity_global_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);
    }

    public function test_local_logout_keeps_global_sso_but_logout_everywhere_invalidates_old_jwts(): void
    {
        $login = $this->withHeaders($this->headers('nexus'))
            ->postJson('/api/account/identity/login', [
                'username' => $this->user->email,
                'password' => 'Test1234!',
                'application' => 'nexus',
            ]);
        $login->assertOk();
        $appToken = (string) $login->json('access_token');

        $established = $this->withHeaders($this->headers('nexus') + ['Authorization' => 'Bearer '.$appToken])
            ->postJson('/api/account/identity/sso/session', ['application' => 'nexus']);
        $cookies = $this->identityCookies($established->headers->getCookies());

        $this->withHeaders(['Authorization' => 'Bearer '.$appToken])
            ->postJson('/api/account/identity/logout')
            ->assertOk();

        $csrf = $this->csrf($cookies, 'cutinapp');
        $restored = $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers('cutinapp') + ['X-Peter-CSRF' => $csrf])
            ->postJson('/api/account/identity/sso/exchange', ['application' => 'cutinapp']);
        $restored->assertOk();
        $restoredToken = (string) $restored->json('data.access_token');

        $secondLegacyToken = auth('api')->login($this->user->fresh());
        $this->withHeaders(['Authorization' => 'Bearer '.$restoredToken])
            ->postJson('/api/account/identity/logout-everywhere')
            ->assertOk();

        $this->assertSame(2, (int) $this->user->fresh()->auth_version);
        $this->assertDatabaseMissing('identity_global_sessions', [
            'user_id' => $this->user->id,
            'revoked_at' => null,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$secondLegacyToken])
            ->getJson('/api/account/identity/security')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');
    }

    private function establish(string $slug)
    {
        return $this->withHeaders($this->headers($slug) + ['Authorization' => 'Bearer '.$this->legacyToken])
            ->postJson('/api/account/identity/sso/session', ['application' => $slug]);
    }

    private function csrf(array $cookies, string $slug): string
    {
        $response = $this->withIdentityCookies($cookies)
            ->withHeaders($this->headers($slug))
            ->getJson("/api/account/identity/sso/csrf?application={$slug}");
        $response->assertOk();
        return (string) $response->json('data.csrf_token');
    }

    private function headers(string $slug): array
    {
        return [
            'X-Peter-App' => $slug,
            'Origin' => "https://{$slug}.petertecnet.com.br",
            'User-Agent' => $this->userAgent(),
        ];
    }

    private function userAgent(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36';
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
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }
        return null;
    }

    private function assertSecureCookie(Cookie $cookie): void
    {
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    private function app(string $name, string $slug): Application
    {
        return $this->applicationFixture($slug, [
            'name' => $name,
            'url' => "https://{$slug}.petertecnet.com.br",
            'is_active' => true,
            'operational_status' => 'operational',
        ]);
    }
}
