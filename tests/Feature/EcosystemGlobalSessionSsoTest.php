<?php

namespace Tests\Feature;

use App\Models\Application;
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

        $profile = Profile::create(['name' => 'Cliente', 'permissions' => []]);
        $this->user = User::create([
            'first_name' => 'Conta',
            'last_name' => 'Global',
            'email' => 'global-sso@example.test',
            'user_name' => 'global-sso-user',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $this->token = auth('api')->login($this->user);
    }

    public function test_authenticated_app_establishes_secure_global_session_and_another_app_restores_login(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->user->applications()->syncWithoutDetaching([
            $source->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
            $destination->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
        ]);

        $sessionResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
            'Origin' => 'https://nexus.petertecnet.com.br',
        ])->postJson('/api/account/sso/session');

        $sessionResponse->assertOk()
            ->assertJsonPath('data.authenticated', true);

        $cookie = $this->globalSessionCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $exchange = $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://cutinapp.petertecnet.com.br',
            ])->postJson('/api/account/sso/session/exchange', [
                'application' => 'cutinapp',
            ]);

        $exchange->assertOk()
            ->assertJsonPath('data.application.slug', 'cutinapp')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_global_session_exchange_rejects_a_different_application_origin(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->user->applications()->syncWithoutDetaching([
            $source->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
            $destination->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
        ]);

        $sessionResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/session');
        $cookie = $this->globalSessionCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->postJson('/api/account/sso/session/exchange', [
                'application' => 'cutinapp',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'A origem não corresponde ao aplicativo solicitado.');
    }

    public function test_global_session_exchange_rejects_a_different_application_header(): void
    {
        $application = $this->application('Nexus', 'nexus', 10);
        $this->user->applications()->syncWithoutDetaching([
            $application->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
        ]);

        $sessionResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/session');
        $cookie = $this->globalSessionCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->withHeaders([
                'X-Peter-App' => 'cutinapp',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->postJson('/api/account/sso/session/exchange', [
                'application' => 'nexus',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'O aplicativo solicitante não corresponde à sessão requisitada.');
    }

    public function test_global_session_is_invalidated_when_auth_version_changes(): void
    {
        $destination = $this->application('Nexus', 'nexus', 10);
        $this->user->applications()->syncWithoutDetaching([
            $destination->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
        ]);

        $sessionResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/session');

        $cookie = $this->globalSessionCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($cookie);

        $this->user->forceFill([
            'auth_version' => (int) ($this->user->auth_version ?? 0) + 1,
        ])->save();

        $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->withHeaders([
                'X-Peter-App' => 'nexus',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->postJson('/api/account/sso/session/exchange', [
                'application' => 'nexus',
            ])
            ->assertNoContent();
    }

    public function test_global_logout_revokes_the_shared_session(): void
    {
        $application = $this->application('Nexus', 'nexus', 10);
        $this->user->applications()->syncWithoutDetaching([
            $application->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
        ]);

        $sessionResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/session');

        $cookie = $this->globalSessionCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->deleteJson('/api/account/sso/session')
            ->assertNoContent();

        $this->withUnencryptedCookie('peter_ecosystem_session', $cookie->getValue())
            ->withHeaders([
                'X-Peter-App' => 'nexus',
                'Origin' => 'https://nexus.petertecnet.com.br',
            ])->postJson('/api/account/sso/session/exchange', [
                'application' => 'nexus',
            ])
            ->assertNoContent();
    }

    private function globalSessionCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'peter_ecosystem_session') {
                return $cookie;
            }
        }

        return null;
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
