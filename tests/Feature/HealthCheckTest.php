<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_critical_dependencies_without_sensitive_details(): void
    {
        $response = $this->getJson('/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'application' => ['status', 'latency_ms'],
                    'database' => ['status', 'latency_ms'],
                    'cache' => ['status', 'latency_ms'],
                    'storage' => ['status', 'latency_ms'],
                ],
            ]);

        $payload = $response->json();
        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('exception', strtolower($serialized));
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->assertStringNotContainsString('secret', strtolower($serialized));
        $this->assertStringNotContainsString('hostname', strtolower($serialized));
    }
}
