<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcosystemSsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_account_can_exchange_a_handoff_only_once(): void
    {
        [$user, $application] = $this->accountWithApplication();
        $token = auth('api')->login($user);

        $handoffResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/account/sso/handoff', ['application' => $application->slug]);

        $handoffResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.application.slug', $application->slug);

        $code = $handoffResponse->json('data.handoff_code');
        $this->assertIsString($code);
        $this->assertSame(64, strlen($code));

        $payload = ['handoff_code' => $code, 'application' => $application->slug];

        $this->postJson('/api/account/sso/exchange', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.application.slug', $application->slug)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'user', 'application']]);

        $this->postJson('/api/account/sso/exchange', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SSO_HANDOFF_INVALID');
    }

    public function test_account_cannot_create_handoff_for_application_without_membership(): void
    {
        [$user] = $this->accountWithApplication();
        $nexus = $this->applicationFixture('nexus', [
            'name' => 'Nexus',
            'url' => 'https://nexus.petertecnet.com.br',
            'is_active' => true,
        ]);
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/account/sso/handoff', ['application' => $nexus->slug])
            ->assertForbidden();
    }

    private function accountWithApplication(): array
    {
        $profile = Profile::create(['name' => 'Usuário', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Conta',
            'email' => 'sso@example.test',
            'user_name' => 'sso-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $application = $this->applicationFixture('rasoio', [
            'name' => 'Rasoio',
            'url' => 'https://rasoio.petertecnet.com.br',
            'is_active' => true,
        ]);
        $user->applications()->attach($application->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        return [$user, $application];
    }
}
