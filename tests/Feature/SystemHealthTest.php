<?php

namespace Tests\Feature;

use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    public function test_liveness_endpoint_reports_application_process_as_alive(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'service', 'timestamp']);
    }

    public function test_readiness_endpoint_reports_required_dependencies(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonStructure([
                'status',
                'ready',
                'service',
                'checks' => ['database', 'cache'],
                'timestamp',
            ]);
    }
}
