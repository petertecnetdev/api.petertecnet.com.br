<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicApiLandingTest extends TestCase
{
    public function test_public_api_landing_is_available_and_does_not_expose_environment(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Public API v1')
            ->assertSee('https://api.petertecnet.com.br/api/v1')
            ->assertSee('/developers', false)
            ->assertSee('/openapi.json', false)
            ->assertDontSee('config(\'app.env\')', false)
            ->assertDontSee('APP_ENV');
    }

    public function test_public_api_documentation_is_complete_and_uses_api_keys(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Public API v1')
            ->assertSee('X-API-Key')
            ->assertSee('establishments:read')
            ->assertSee('catalog:read')
            ->assertSee('Rate limits')
            ->assertSee('Webhooks assinados')
            ->assertDontSee('/api/establishment', false);
    }

    public function test_developer_public_pages_are_available(): void
    {
        foreach (['/developers', '/status', '/changelog', '/terms', '/privacy', '/deprecation'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_api_discovery_endpoint_describes_stable_public_contract(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJson([
                'name' => 'Peter Tecnet Public API',
                'visibility' => 'public',
                'format' => 'JSON',
                'transport' => 'HTTPS',
                'stable_version' => 'v1',
            ])
            ->assertJsonPath('base_url', url('/api/v1'))
            ->assertJsonPath('sandbox_base_url', url('/api/sandbox/v1'))
            ->assertJsonPath('authentication.public_api', 'X-API-Key com scopes por integração');
    }

    public function test_public_resource_contract_requires_an_api_key_before_database_access(): void
    {
        $this->getJson('/api/v1/establishments')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'api_key_required');

        $this->getJson('/api/sandbox/v1/items')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'api_key_required');
    }
}
