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

    public function test_issue_intelligence_returns_safe_payload_during_schema_drift(): void
    {
        if (! Schema::hasTable('operational_issues') || ! Schema::hasColumn('operational_issues', 'impact_score')) {
            $this->markTestSkipped('operational_issues schema unavailable for drift simulation.');
        }

        Schema::table('operational_issues', function (Blueprint $table) {
            $table->dropColumn('impact_score');
        });

        $this->assertFalse(Schema::hasColumn('operational_issues', 'impact_score'));

        $payload = app(OperationalIssueService::class)->intelligence();

        $this->assertTrue($payload['degraded']);
        $this->assertSame(0, $payload['summary']['active_alerts']);
        $this->assertSame([], $payload['alerts']);
        $this->assertSame([], $payload['deployments']);
        $this->assertSame([], $payload['slos']);
    }
}
