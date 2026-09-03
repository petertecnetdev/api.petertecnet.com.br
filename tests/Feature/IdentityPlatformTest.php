<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\TotpService;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.password.compromised_check', false);
    }

    public function test_central_login_issues_a_revocable_device_session(): void
    {
        [$user, $application] = $this->account();

        $response = $this->withHeader('User-Agent', 'Identity Test Browser')
            ->postJson('/api/account/identity/login', [
                'username' => $user->email,
                'password' => 'Test1234!',
                'application' => $application->slug,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session.auth_method', 'password')
            ->assertJsonPath('application.slug', $application->slug)
            ->assertJsonStructure(['access_token', 'session' => ['id', 'device', 'expires_at']]);

        $sessionId = $response->json('session.id');
        $token = $response->json('access_token');

        $this->assertDatabaseHas('identity_sessions', [
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'app_id' => $application->id,
            'revoked_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/account/identity/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.current', true);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/account/identity/sessions/' . $sessionId)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/account/identity/sessions')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_two_factor_blocks_password_login_until_totp_is_verified(): void
    {
        [$user, $application] = $this->account();
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        IdentitySecuritySetting::query()->create([
            'user_id' => $user->id,
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);

        $login = $this->postJson('/api/account/identity/login', [
            'username' => $user->email,
            'password' => 'Test1234!',
            'application' => $application->slug,
        ]);

        $login->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissing(['access_token']);

        $challenge = $login->json('challenge');
        $reflection = new \ReflectionClass($totp);
        $method = $reflection->getMethod('code');
        $method->setAccessible(true);
        $counter = intdiv(time(), (int) config('identity.two_factor.period', 30));
        $code = $method->invoke($totp, $secret, $counter, (int) config('identity.two_factor.digits', 6));

        $this->postJson('/api/account/identity/two-factor/verify', [
            'challenge' => $challenge,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session.auth_method', 'password+totp')
            ->assertJsonStructure(['access_token']);
    }

    public function test_legacy_jwt_without_sid_remains_valid_during_migration(): void
    {
        [$user] = $this->account();
        $legacyToken = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $legacyToken)
            ->getJson('/api/account/identity/security')
            ->assertOk();
    }

    private function account(): array
    {
        $profile = Profile::query()->create(['name' => 'Usuário', 'permissions' => []]);
        $user = User::query()->create([
            'first_name' => 'Identity',
            'email' => 'identity@example.test',
            'user_name' => 'identity-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
        $application = $this->applicationFixture('identity-app', [
            'name' => 'Identity App',
            'url' => 'https://identity.petertecnet.com.br',
            'is_active' => true,
        ]);
        $user->applications()->attach($application->id, [
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $application];
    }
}
