<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PublicAuthConfigTest extends TestCase
{
    public function test_it_exposes_only_public_google_configuration(): void
    {
        Config::set('services.google.client_id', 'public-google-client-id.apps.googleusercontent.com');
        Config::set('services.google.client_secret', 'must-not-be-exposed');

        $response = $this->getJson('/api/account/identity/providers');

        $response
            ->assertOk()
            ->assertJson([
                'google' => [
                    'enabled' => true,
                    'client_id' => 'public-google-client-id.apps.googleusercontent.com',
                ],
            ])
            ->assertJsonMissing(['client_secret' => 'must-not-be-exposed']);

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_reports_google_as_disabled_when_client_id_is_missing(): void
    {
        Config::set('services.google.client_id', '');

        $this->getJson('/api/account/identity/providers')
            ->assertOk()
            ->assertJson([
                'google' => [
                    'enabled' => false,
                    'client_id' => null,
                ],
            ]);
    }
}
