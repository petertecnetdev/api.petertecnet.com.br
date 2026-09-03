<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_correlates_deploy_detects_anomaly_and_prepares_supervised_repair_plan(): void
    {
        $now = now();
        $issueId = DB::table('operational_issues')->insertGetId([
            'fingerprint' => 'EVT-TESTINTEL01',
            'title' => 'Payment provider failed after deploy',
            'category' => 'server_exception',
            'domain' => 'payments',
            'status' => 'new',
            'severity' => 'critical',
            'impact_score' => 92,
            'occurrence_count' => 6,
            'users_affected_count' => 4,
            'applications_affected_count' => 2,
            'establishments_affected_count' => 2,
            'first_seen_at' => $now->copy()->subMinutes(10),
            'last_seen_at' => $now,
            'latest_http_status' => 500,
            'latest_error_code' => 'PAYMENT_PROVIDER_FAILURE',
            'latest_method' => 'POST',
            'latest_route' => '/api/payments',
            'latest_message' => 'Payment provider failed',
            'source_commit' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'context' => json_encode([
                'technical' => [
                    'exception_class' => 'RuntimeException',
                    'file' => 'app/Services/Payments/PaymentService.php',
                    'line' => 87,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('operational_deployments')->insert([
            'application_id' => null,
            'environment' => 'production',
            'version' => '2026.09.03',
            'commit_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'previous_commit_sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'status' => 'succeeded',
            'source' => 'test',
            'deployed_at' => $now->copy()->subMinutes(15),
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (range(1, 6) as $interactionId) {
            DB::table('operational_issue_occurrences')->insert([
                'operational_issue_id' => $issueId,
                'interaction_id' => 9000 + $interactionId,
                'application_id' => null,
                'user_id' => null,
                'establishment_id' => null,
                'occurred_at' => $now->copy()->subMinutes($interactionId),
                'http_status' => 500,
                'request_id' => 'req-'.$interactionId,
                'correlation_id' => null,
                'duration_ms' => 1200,
                'metadata' => null,
                'created_at' => $now,
            ]);
        }

        $service = app(OperationalIntelligenceService::class);
        $issue = DB::table('operational_issues')->find($issueId);
        $analysis = $service->analyze($issue);

        $this->assertTrue($analysis['deploy_correlation']['matched']);
        $this->assertTrue($analysis['deploy_correlation']['strong_temporal_correlation']);
        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $analysis['deploy_correlation']['commit_sha']);
        $this->assertTrue($analysis['anomaly']['detected']);
        $this->assertNotEmpty($analysis['journeys']);
        $this->assertNotEmpty($analysis['runbooks']);
        $this->assertSame('app/Services/Payments/PaymentService.php', $analysis['probable_cause']['candidate_files'][0]['path']);
        $this->assertTrue($analysis['alert_policy']['should_alert']);
        $this->assertSame('critical', $analysis['alert_policy']['level']);
        $this->assertTrue($analysis['rollback']['recommended']);
        $this->assertTrue($analysis['rollback']['requires_manual_approval']);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $analysis['rollback']['target_commit']);
        $this->assertFalse($analysis['correction_assistant']['automatic_production_changes']);
        $this->assertSame('supervised', $analysis['correction_assistant']['mode']);
        $this->assertDatabaseHas('operational_alerts', ['operational_issue_id' => $issueId, 'status' => 'open', 'level' => 'critical']);

        $plan = $service->createRepairPlan($issue);
        $this->assertSame('suggested', $plan['status']);
        $this->assertNotEmpty($plan['candidate_files']);
        $this->assertNotEmpty($plan['validation_gates']);
        $this->assertDatabaseHas('operational_repair_plans', ['id' => $plan['id'], 'operational_issue_id' => $issueId, 'status' => 'suggested']);
    }
}
