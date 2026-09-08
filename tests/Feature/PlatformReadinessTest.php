<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PlatformReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_checks_database_cache_auth_and_idempotency_infrastructure(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertJsonPath('checks.idempotency', true)
            ->assertJsonPath('checks.auth_guard', true)
            ->assertJsonStructure(['request_id', 'timestamp']);
    }

    public function test_authenticated_mutation_probe_exercises_application_context_and_idempotency_replay(): void
    {
        $user = User::create([
            'first_name' => 'Health Probe',
            'email' => 'health-probe@platform.test',
            'user_name' => 'health-probe',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
            'Idempotency-Key' => 'health-probe-'.str_repeat('e', 28),
            'X-Request-ID' => 'health-probe-request-id',
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/health/mutation-probe')
            ->assertOk()
            ->assertHeader('Idempotency-Status', 'created')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('application.slug', 'cutinapp');

        $probeId = $first->json('probe_id');
        $this->assertNotEmpty($probeId);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/health/mutation-probe')
            ->assertOk()
            ->assertHeader('Idempotency-Status', 'replayed')
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('probe_id', $probeId);
    }
}
