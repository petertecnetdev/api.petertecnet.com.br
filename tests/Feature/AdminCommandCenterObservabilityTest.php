<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminCommandCenterObservabilityTest extends TestCase
{
    public function test_observability_storage_is_provisioned(): void
    {
        $this->assertTrue(Schema::hasTable('operational_issues'));
        $this->assertTrue(Schema::hasTable('operational_issue_occurrences'));
        $this->assertTrue(Schema::hasTable('operational_issue_history'));
        $this->assertTrue(Schema::hasTable('operational_slos'));
        $this->assertTrue(Schema::hasTable('admin_metric_rollups'));
    }

    public function test_overview_separates_health_from_observability_coverage(): void
    {
        $app = $this->applicationFixture('mission-observability-test', [
            'name' => 'Mission Observability Test',
            'url' => 'https://example.test',
            'is_active' => true,
        ]);

        DB::table('admin_service_probes')->insert([
            'application_id' => $app->id,
            'status' => 'operational',
            'http_status' => 200,
            'latency_ms' => 120,
            'error' => null,
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admin_runtime_heartbeats')->updateOrInsert(['service' => 'scheduler'], [
            'status' => 'healthy', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admin_runtime_heartbeats')->updateOrInsert(['service' => 'probe'], [
            'status' => 'healthy', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $overview = app(OperationalTelemetryService::class)->overview();

        $this->assertArrayHasKey('score', $overview);
        $this->assertArrayHasKey('observability_score', $overview);
        $this->assertArrayHasKey('status', $overview);
        $this->assertGreaterThanOrEqual(0, $overview['score']);
        $this->assertLessThanOrEqual(100, $overview['score']);
        $this->assertGreaterThanOrEqual(0, $overview['observability_score']);
        $this->assertLessThanOrEqual(100, $overview['observability_score']);
    }

    public function test_issue_engine_correlates_failed_queue_jobs_without_duplicates(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            $this->markTestSkipped('failed_jobs table unavailable in this database configuration.');
        }

        DB::table('failed_jobs')->insert([
            'uuid' => 'mission-control-test-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Synthetic failure for Mission Control test',
            'failed_at' => now(),
        ]);

        $service = app(OperationalIssueService::class);
        $service->evaluateFromCurrentState();
        $service->evaluateFromCurrentState();

        $rows = DB::table('operational_issues')->where('fingerprint', 'runtime:queue:failed_jobs')->get();
        $this->assertCount(1, $rows);
        $this->assertGreaterThanOrEqual(2, (int) $rows->first()->occurrence_count);
    }
}
