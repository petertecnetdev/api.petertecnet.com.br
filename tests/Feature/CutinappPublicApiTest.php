<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutinappPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutinapp_public_config_is_available_without_authentication(): void
    {
        $this->getJson('/api/cutinapp/config')
            ->assertOk()
            ->assertJsonPath('app', 'cutinapp')
            ->assertJsonStructure(['google_client_id', 'app']);
    }

    public function test_cutinapp_public_events_endpoint_returns_paginated_collection(): void
    {
        $this->getJson('/api/cutinapp/events')
            ->assertOk()
            ->assertJsonStructure([
                'events' => [
                    'data',
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    public function test_cutinapp_operational_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cutinapp/productions/mine')->assertUnauthorized();
        $this->getJson('/api/cutinapp/passes/mine')->assertUnauthorized();
        $this->postJson('/api/cutinapp/checkin', ['token' => 'CUT-TEST'])->assertUnauthorized();
    }
}
