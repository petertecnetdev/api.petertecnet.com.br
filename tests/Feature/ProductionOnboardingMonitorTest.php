<?php

namespace Tests\Feature;

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionOnboardingMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_opens_and_resolves_a_production_creation_error_rate_issue(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $this->interaction((int) $application->id, $i < 3 ? 'error' : 'success');
        }

        $this->artisan('operations:monitor-production-onboarding')->assertSuccessful();

        $this->assertDatabaseHas('operational_issues', [
            'fingerprint' => 'funnel:cutinapp:production-create:error-rate',
            'application_id' => $application->id,
            'status' => 'new',
            'domain' => 'producer_onboarding',
            'priority' => 'P0',
        ]);

        for ($i = 0; $i < 15; $i++) {
            $this->interaction((int) $application->id, 'success');
        }

        $this->artisan('operations:monitor-production-onboarding')->assertSuccessful();

        $this->assertDatabaseHas('operational_issues', [
            'fingerprint' => 'funnel:cutinapp:production-create:error-rate',
            'status' => 'resolved',
        ]);
    }

    private function interaction(int $appId, string $outcome): void
    {
        DB::table('interactions')->insert([
            'app_id' => $appId,
            'interaction_type' => $outcome === 'error' ? 'request_error' : 'create',
            'outcome' => $outcome,
            'severity' => $outcome === 'error' ? 'critical' : 'normal',
            'environment' => 'testing',
            'route' => 'api/v1/apps/{application}/organizations',
            'method' => 'POST',
            'name' => 'Production create '.$outcome,
            'content' => json_encode(['status' => $outcome === 'error' ? 500 : 201]),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
