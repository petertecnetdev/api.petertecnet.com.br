<?php

namespace Tests\Feature;

use App\Models\ApiCredential;
use App\Models\ApiProject;
use App\Models\Application;
use App\Models\Item;
use App\Models\OauthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeterPlatformDeveloperApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_api_key_is_scoped_to_its_application(): void
    {
        $app = $this->app('Nexus', 'nexus');
        $other = $this->app('Rasoio', 'rasoio');
        $project = ApiProject::create([
            'application_id' => $app->id,
            'name' => 'External frontend',
            'environment' => 'production',
            'allowed_scopes' => ['catalog.read'],
        ]);
        [, $key] = ApiCredential::issue($project, 'frontend', ['catalog.read']);

        $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/apps/nexus/platform/items')
            ->assertOk();

        $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/apps/rasoio/platform/items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'APPLICATION_SCOPE_MISMATCH');
    }

    public function test_missing_scope_is_rejected(): void
    {
        $app = $this->app('Nexus', 'nexus');
        $project = ApiProject::create([
            'application_id' => $app->id,
            'name' => 'Read establishments only',
            'environment' => 'production',
            'allowed_scopes' => ['establishments.read'],
        ]);
        [, $key] = ApiCredential::issue($project, 'limited', ['establishments.read']);

        $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/apps/nexus/platform/items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
    }

    public function test_sandbox_credentials_never_read_live_items(): void
    {
        $app = $this->app('Nexus', 'nexus');
        Item::create(['app_id' => $app->id, 'name' => 'LIVE SECRET', 'price' => 10, 'status' => true]);
        $project = ApiProject::create([
            'application_id' => $app->id,
            'name' => 'Sandbox',
            'environment' => 'sandbox',
            'allowed_scopes' => ['catalog.read', 'sandbox.read', 'sandbox.write'],
        ]);
        [, $key] = ApiCredential::issue($project, 'sandbox', ['catalog.read', 'sandbox.read', 'sandbox.write']);

        $this->withHeader('X-API-Key', $key)
            ->getJson('/api/v1/apps/nexus/platform/items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SANDBOX_LIVE_RESOURCE_ACCESS_DENIED');

        $create = $this->withHeaders(['X-API-Key' => $key, 'Idempotency-Key' => 'sandbox-create-1'])
            ->postJson('/api/v1/apps/nexus/platform/sandbox/items', ['data' => ['name' => 'TEST ITEM']])
            ->assertCreated();

        $this->assertSame('TEST ITEM', $create->json('data.payload.name'));
        $this->assertDatabaseMissing('items', ['name' => 'TEST ITEM']);
    }

    public function test_external_writes_require_and_replay_idempotency_keys(): void
    {
        $app = $this->app('Nexus', 'nexus');
        $project = ApiProject::create([
            'application_id' => $app->id,
            'name' => 'Sandbox',
            'environment' => 'sandbox',
            'allowed_scopes' => ['sandbox.write'],
        ]);
        [, $key] = ApiCredential::issue($project, 'sandbox', ['sandbox.write']);

        $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/apps/nexus/platform/sandbox/orders', ['data' => ['amount' => 10]])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $headers = ['X-API-Key' => $key, 'Idempotency-Key' => 'order-abc'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/apps/nexus/platform/sandbox/orders', ['data' => ['amount' => 10]])->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/apps/nexus/platform/sandbox/orders', ['data' => ['amount' => 10]])->assertCreated();
        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
    }

    public function test_oauth_client_credentials_uses_same_project_scope_model(): void
    {
        $app = $this->app('Nexus', 'nexus');
        $project = ApiProject::create([
            'application_id' => $app->id,
            'name' => 'OAuth project',
            'environment' => 'production',
            'allowed_scopes' => ['catalog.read'],
        ]);
        [$client, $secret] = OauthClient::issue($project, 'server', ['catalog.read']);

        $token = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'scope' => 'catalog.read',
        ])->assertOk()->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/apps/nexus/platform/items')
            ->assertOk();
    }

    public function test_legacy_routes_are_marked_deprecated_but_v1_is_not(): void
    {
        $legacy = $this->postJson('/api/auth/login', []);
        $this->assertSame('true', $legacy->headers->get('Deprecation'));

        $app = $this->app('Nexus', 'nexus');
        $v1 = $this->getJson('/api/v1/apps/nexus/items')->assertOk();
        $this->assertNull($v1->headers->get('Deprecation'));
    }

    private function app(string $name, string $slug): Application
    {
        return Application::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function user(): User
    {
        return User::create([
            'first_name' => 'Dev',
            'email' => 'dev@example.test',
            'user_name' => 'dev-platform',
            'password' => Hash::make('Test1234!'),
        ]);
    }
}
