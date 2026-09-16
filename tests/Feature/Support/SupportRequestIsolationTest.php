<?php

namespace Tests\Feature\Support;

use App\Models\SupportRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportRequestIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_request_belongs_to_resolved_application_context(): void
    {
        $appA = $this->application('support-a');
        $appB = $this->application('support-b');

        $request = SupportRequest::create([
            'application_id' => $appA,
            'category' => 'payment',
            'priority' => 'critical',
            'status' => 'open',
            'description' => 'Checkout failed after payment approval.',
            'correlation_id' => 'corr-support-a',
            'metadata' => ['reported_application_id' => $appB],
        ]);

        $this->assertSame($appA, $request->application_id);
        $this->assertSame($appB, $request->metadata['reported_application_id']);
        $this->assertDatabaseHas('support_requests', [
            'id' => $request->id,
            'application_id' => $appA,
            'category' => 'payment',
            'priority' => 'critical',
        ]);
    }

    public function test_same_correlation_id_is_scoped_by_application_when_querying_incidents(): void
    {
        $appA = $this->application('support-c');
        $appB = $this->application('support-d');

        SupportRequest::create([
            'application_id' => $appA,
            'category' => 'bug',
            'status' => 'open',
            'description' => 'Application A incident.',
            'correlation_id' => 'shared-correlation',
        ]);
        SupportRequest::create([
            'application_id' => $appB,
            'category' => 'bug',
            'status' => 'open',
            'description' => 'Application B incident.',
            'correlation_id' => 'shared-correlation',
        ]);

        $forA = SupportRequest::query()
            ->where('application_id', $appA)
            ->where('correlation_id', 'shared-correlation')
            ->get();

        $this->assertCount(1, $forA);
        $this->assertSame('Application A incident.', $forA->first()->description);
    }

    public function test_financial_support_categories_remain_explicit_for_p0_triage(): void
    {
        $appId = $this->application('support-finance');

        foreach (['payment', 'payout'] as $category) {
            SupportRequest::create([
                'application_id' => $appId,
                'category' => $category,
                'priority' => 'critical',
                'status' => 'open',
                'description' => "Critical {$category} incident.",
            ]);
        }

        $criticalFinancial = SupportRequest::query()
            ->where('application_id', $appId)
            ->where('priority', 'critical')
            ->whereIn('category', ['payment', 'payout'])
            ->count();

        $this->assertSame(2, $criticalFinancial);
    }

    private function application(string $slug): int
    {
        return DB::table('applications')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
