<?php

namespace Tests\Feature;

use App\Domain\Automation\Workers\DiscoveryCandidateQualityWorker;
use App\Jobs\RunAutomationWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AutomationWorkersTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_dispatch_requires_global_and_per_application_opt_in(): void
    {
        Queue::fake();
        $app = $this->applicationFixture('cutinapp');

        config()->set('automation_workers.enabled', false);
        $this->artisan('automation:configure', [
            'application' => $app->slug,
            'worker' => 'discovery_quality',
            '--enable' => true,
        ])->assertExitCode(0);
        $this->artisan('automation:dispatch')->assertExitCode(0);
        Queue::assertNothingPushed();

        config()->set('automation_workers.enabled', true);
        $this->artisan('automation:dispatch')->assertExitCode(0);
        Queue::assertPushed(RunAutomationWorker::class, 1);

        $this->artisan('automation:dispatch')->assertExitCode(0);
        Queue::assertPushed(RunAutomationWorker::class, 1);
        $this->assertDatabaseCount('automation_worker_runs', 1);

        $this->artisan('automation:configure', [
            'application' => $app->slug,
            'worker' => 'discovery_quality',
            '--disable' => true,
        ])->assertExitCode(0);
        $this->artisan('automation:dispatch')->assertExitCode(0);
        Queue::assertPushed(RunAutomationWorker::class, 1);
    }

    public function test_quality_worker_only_flags_pending_duplicates_from_same_application(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $other = $this->applicationFixture('peter-tecnet');

        $first = $this->candidate($app->id, 101, 'same-content', 'pending');
        $second = $this->candidate($app->id, 102, 'same-content', 'pending');
        $reviewed = $this->candidate($app->id, 103, 'same-content', 'drafted');
        $outside = $this->candidate($other->id, 104, 'same-content', 'pending');

        $result = app(DiscoveryCandidateQualityWorker::class)->run($app->id);
        $this->assertSame(2, $result['flagged_as_possible_duplicate']);

        foreach ([$first, $second] as $id) {
            $this->assertDatabaseHas('event_discovery_candidates', [
                'id' => $id,
                'status' => 'possible_duplicate',
            ]);
        }
        $this->assertDatabaseHas('event_discovery_candidates', ['id' => $reviewed, 'status' => 'drafted']);
        $this->assertDatabaseHas('event_discovery_candidates', ['id' => $outside, 'status' => 'pending']);
        $this->assertSame(0, app(DiscoveryCandidateQualityWorker::class)
            ->run($app->id)['flagged_as_possible_duplicate']);
    }

    public function test_dispatched_job_respects_revocation_before_execution(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $id = DB::table('automation_worker_runs')->insertGetId([
            'app_id' => $app->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('automation_workers.enabled', true);
        app(RunAutomationWorker::class, ['runId' => $id])->handle();

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $id,
            'status' => 'skipped',
        ]);
    }

    private function candidate(int $appId, int $sourceId, string $key, string $status): int
    {
        return DB::table('event_discovery_candidates')->insertGetId([
            'app_id' => $appId,
            'source_id' => $sourceId,
            'source_event_key' => hash('sha256', 'source-'.$sourceId),
            'content_key' => hash('sha256', $key),
            'title' => 'Evento autorizado',
            'start_at' => now()->addDays(7),
            'city' => 'Brasília',
            'uf' => 'DF',
            'source_url' => 'https://example.org/events/'.$sourceId,
            'status' => $status,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
