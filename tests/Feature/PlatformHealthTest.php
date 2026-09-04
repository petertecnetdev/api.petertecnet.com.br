<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformHealthTest extends TestCase
{
    public function test_liveness_probe_reports_ok(): void
    {
        $response = $this->getJson('/api/health/live');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'petertecnet-api')
            ->assertJsonStructure(['status', 'service', 'timestamp']);
    }

    public function test_readiness_probe_checks_critical_dependencies(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['ok' => 1]]);
        Cache::shouldReceive('put')->once();
        Cache::shouldReceive('get')->once()->andReturn('ok');
        Cache::shouldReceive('forget')->once();

        $response = $this->getJson('/api/health/ready');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertJsonPath('checks.runtime', true)
            ->assertJsonStructure(['status', 'service', 'checks', 'timestamp']);
    }
}
