<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\IdentityDevice;
use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySession;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.password.compromised_check', false);
        config()->set('identity.global_sso.cache_store', 'array');
        config()->set('identity.access_token.ttl_minutes', 30);
        config()->set('identity.legacy_tokens.mode', 'observe');
        config()->set('identity.legacy_tokens.reject_after_sunset', false);
    }

    public function test_protocol_contract_is_public_and_versioned(): void
    {
        $this->getJson('/api/account/identity/protocol')
            ->assertOk()
            ->assertJsonPath('data.protocol_version', '3.0')
            ->assertJsonPath('data.capabilities.global_sso', true)
            ->assertJsonPath('data.capabilities.trusted_devices', true);
    }

    public function test_login_creates_a_stable_device_bound_short_lived_session(): void
    {
        [$user, $application] = $this->account();
        $response = $this->login($user, $application, 'browser-device-001');

        $response->assertOk()
            ->assertJsonPath('expires_in', 1800)
            ->assertJsonPath('session.device.id', 'browser-device-001')
            ->assertJsonPath('session.device.platform', 'Linux')
            ->assertJsonPath('session.device.browser', 'Chrome');

        $device = IdentityDevice::query()->where('user_id', $user->id)->where('device_id', 'browser-device-001')->firstOrFail();
        $this->assertFalse($device->trusted);
        $this->assertDatabaseHas('identity_sessions', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'app_id' => $application->id,
        ]);
    }

    public function test_critical_action_requires_and_consumes_step_up(): void
    {
        [$user, $application] = $this->account();
        $login = $this->login($user, $application, 'browser-device-stepup');
        $token = $login->json('access_token');

        $this->withHeaders($this->headers($token, 'browser-device-stepup'))
            ->deleteJson('/api/account/identity/sessions')
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $grant = $this->withHeaders($this->headers($token, 'browser-device-stepup'))
            ->postJson('/api/account/identity/step-up/password', [
                'action' => 'revoke_all_sessions',
                'current_password' => 'Test1234!',
            ])->assertOk()->json('data.token');

        $this->withHeaders(array_merge($this->headers($token, 'browser-device-stepup'), ['X-Peter-Step-Up' => $grant]))
            ->deleteJson('/api/account/identity/sessions')
            ->assertOk();

        $this->assertNotNull(IdentitySession::query()->where('user_id', $user->id)->firstOrFail()->revoked_at);
        $this->assertGreaterThan(1, (int) $user->fresh()->auth_version);
    }

    public function test_global_sso_is_blocked_until_rollout_enables_the_account(): void
    {
        config()->set('identity.rollout.global_sso_enabled', false);
        config()->set('identity.rollout.default_percentage', 0);
        [$user, $application] = $this->account();
        $token = $this->login($user, $application, 'browser-device-disabled')->json('access_token');

        $this->withHeaders(array_merge($this->headers($token, 'browser-device-disabled'), [
            'Origin' => $application->url,
            'X-Peter-App' => $application->slug,
        ]))->postJson('/api/account/identity/sso/session', ['application' => $application->slug])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDENTITY_SSO_ROLLOUT_DISABLED');
    }

    public function test_enabled_global_sso_restores_an_app_session_with_csrf_and_rotates_refresh(): void
    {
        config()->set('identity.rollout.global_sso_enabled', true);
        config()->set('identity.rollout.default_percentage', 100);
        [$user, $application] = $this->account();
        $token = $this->login($user, $application, 'browser-device-sso')->json('access_token');
        $originHeaders = array_merge($this->headers($token, 'browser-device-sso'), [
            'Origin' => $application->url,
            'X-Peter-App' => $application->slug,
        ]);

        $established = $this->withHeaders($originHeaders)
            ->postJson('/api/account/identity/sso/session', ['application' => $application->slug])
            ->assertOk();

        $sessionCookie = $established->getCookie('peter_ecosystem_session', false);
        $refreshCookie = $established->getCookie('peter_ecosystem_refresh', false);
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($refreshCookie);
        $this->assertDatabaseMissing('identity_global_sessions', ['session_token_hash' => $sessionCookie->getValue()]);
        $this->assertSame(hash('sha256', $sessionCookie->getValue()), IdentityGlobalSession::query()->firstOrFail()->session_token_hash);

        $cookies = [
            'peter_ecosystem_session' => $sessionCookie->getValue(),
            'peter_ecosystem_refresh' => $refreshCookie->getValue(),
        ];
        $anonymousHeaders = [
            'User-Agent' => $this->ua,
            'Origin' => $application->url,
            'X-Peter-App' => $application->slug,
            'X-Peter-Device' => 'browser-device-sso',
        ];

        $csrf = $this->withCookies($cookies)->withHeaders($anonymousHeaders)
            ->getJson('/api/account/identity/sso/csrf?application='.$application->slug)
            ->assertOk()
            ->json('data.csrf_token');

        $exchange = $this->withCookies($cookies)->withHeaders(array_merge($anonymousHeaders, ['X-Peter-CSRF' => $csrf]))
            ->postJson('/api/account/identity/sso/exchange', ['application' => $application->slug])
            ->assertOk()
            ->assertJsonPath('data.session.device.id', 'browser-device-sso')
            ->assertJsonStructure(['data' => ['access_token', 'session']]);

        $this->assertNotNull($exchange->getCookie('peter_ecosystem_refresh', false));
    }

    public function test_legacy_tokens_are_observed_before_enforcement(): void
    {
        [$user] = $this->account();
        $legacy = auth('api')->login($user);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$legacy,
            'User-Agent' => $this->ua,
            'X-Peter-App' => 'identity-app',
        ])->getJson('/api/account/identity/security')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('X-Peter-Identity-Migration', 'legacy-token-observed');

        $this->assertDatabaseHas('ecosystem_audit_logs', [
            'user_id' => $user->id,
            'action' => 'identity.legacy_token_seen',
        ]);
    }

    public function test_operator_can_read_identity_observability(): void
    {
        [$user, $application] = $this->account(['ecosystem_manage']);
        $token = $this->login($user, $application, 'browser-device-ops')->json('access_token');

        $this->withHeaders($this->headers($token, 'browser-device-ops'))
            ->getJson('/api/account/identity/operations/observability?minutes=60')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'authentication' => ['successful', 'failed', 'success_rate', 'sso_restores'],
                'sessions' => ['application_active', 'global_active', 'known_devices'],
                'legacy' => ['tokens_seen', 'mode', 'ready_to_enforce'],
                'infrastructure' => ['redis_available', 'database_fallback'],
            ]]);
    }

    private function login(User $user, $application, string $deviceId)
    {
        return $this->withHeaders([
            'User-Agent' => $this->ua,
            'X-Peter-Device' => $deviceId,
            'X-Peter-Device-Name' => 'Notebook de teste',
            'X-Peter-App' => $application->slug,
        ])->postJson('/api/account/identity/login', [
            'username' => $user->email,
            'password' => 'Test1234!',
            'application' => $application->slug,
        ]);
    }

    private function headers(string $token, string $deviceId): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'User-Agent' => $this->ua,
            'X-Peter-Device' => $deviceId,
            'X-Peter-Device-Name' => 'Notebook de teste',
            'X-Peter-App' => 'identity-app',
        ];
    }

    private function account(array $permissions = []): array
    {
        $profile = Profile::query()->create(['name' => 'Identity Operator', 'permissions' => $permissions]);
        $user = User::query()->create([
            'first_name' => 'Identity',
            'email' => 'identity-'.uniqid().'@example.test',
            'user_name' => 'identity-'.uniqid(),
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
        $application = $this->applicationFixture('identity-app', [
            'name' => 'Identity App',
            'url' => 'https://identity.petertecnet.com.br',
            'is_active' => true,
            'self_service_access' => true,
        ]);
        $user->applications()->syncWithoutDetaching([
            $application->id => ['role' => 'member', 'status' => 'active', 'joined_at' => now()],
        ]);

        return [$user, $application];
    }
}
