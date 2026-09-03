<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use App\Services\Operations\ResilientOperationalIssueService;
use App\Services\Operations\ResilientOperationalTelemetryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MissionControlResilienceTest extends TestCase
{
    public function test_container_uses_resilient_read_services(): void
    {
        $this->assertInstanceOf(
            ResilientOperationalTelemetryService::class,
            app(OperationalTelemetryService::class)
        );

        $this->assertInstanceOf(
            ResilientOperationalIssueService::class,
            app(OperationalIssueService::class)
        );
    }

    public function test_overview_returns_safe_snapshot_during_observability_schema_drift(): void
    {
        if (! Schema::hasTable('admin_service_probes') || ! Schema::hasColumn('admin_service_probes', 'error')) {
            $this->markTestSkipped('admin_service_probes schema unavailable for drift simulation.');
        }

        Schema::table('admin_service_probes', function (Blueprint $table) {
            $table->dropColumn('error');
        });

        $overview = app(OperationalTelemetryService::class)->overview();

        $this->assertSame('indeterminate', $overview['status']);
        $this->assertTrue($overview['degraded']);
        $this->assertArrayHasKey('applications', $overview);
        $this->assertArrayHasKey('runtime', $overview);
        $this->assertArrayHasKey('queues', $overview);
        $this->assertArrayHasKey('security', $overview);
        $this->assertNotEmpty($overview['warnings']);
    }

    public function test_issue_intelligence_keeps_a_stable_read_contract(): void
    {
        $payload = app(OperationalIssueService::class)->intelligence();

        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('alerts', $payload);
        $this->assertArrayHasKey('deployments', $payload);
        $this->assertArrayHasKey('slos', $payload);
        $this->assertArrayHasKey('active_alerts', $payload['summary']);
        $this->assertArrayHasKey('critical_alerts', $payload['summary']);
        $this->assertArrayHasKey('deployments_24h', $payload['summary']);
        $this->assertArrayHasKey('repair_plans', $payload['summary']);
    }
}
