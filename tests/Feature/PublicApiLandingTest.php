<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicApiLandingTest extends TestCase
{
    public function test_public_api_landing_is_available_and_does_not_expose_environment(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('API pública Peter Tecnet')
            ->assertSee('https://api.petertecnet.com.br/api')
            ->assertSee('/docs', false)
            ->assertDontSee('config(\'app.env\')', false)
            ->assertDontSee('APP_ENV');
    }

    public function test_public_api_documentation_is_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Documentação pública')
            ->assertSee('/api/establishment')
            ->assertSee('Authorization: Bearer SEU_TOKEN');
    }

    public function test_api_discovery_endpoint_describes_public_integration(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJson([
                'name' => 'Peter Tecnet API',
                'visibility' => 'public',
                'format' => 'JSON',
                'transport' => 'HTTPS',
            ])
            ->assertJsonPath('authentication', 'Bearer token em endpoints protegidos');
    }
}
