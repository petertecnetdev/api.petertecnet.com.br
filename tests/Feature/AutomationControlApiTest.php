<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RunAutomationWorker;
use Tests\TestCase;

final class AutomationControlApiTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        $profile = Profile::query()->firstOrCreate(['name' => 'Admin Automação'], ['permissions' => []]);
        return User::query()->create([
            'first_name' => 'Admin',
            'email' => 'petertecnet@gmail.com',
            'user_name' => 'admin-automation-tests',
            'password' => Hash::make('Testing123!'),
            'profile_id' => $profile->id,
        ]);
    }

    public function test_anonymous_users_cannot_read_or_change_worker_state(): void
    {
        $this->applicationFixture('cutinapp');
        $base = '/api/v1/apps/cutinapp/admin/automations';
        $this->getJson($base)->assertUnauthorized();
        $this->putJson($base.'/workers/discovery_quality', [
            'enabled' => true, 'interval_minutes' => 120,
        ])->assertUnauthorized();
    }

    public function test_app_control_requires_authorization_and_isolated_from_other_apps(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $other = $this->applicationFixture('peter-tecnet');
        $user = $this->root();
        $token = auth('api')->login($user);
        $url = '/api/v1/apps/cutinapp/admin/automations';
        $otherUrl = '/api/v1/apps/peter-tecnet/admin/automations';

        $this->withToken($token)->getJson($url)->assertOk()
            ->assertJsonPath('data.global_enabled', false);

        $this->withToken($token)->putJson($url.'/workers/discovery_quality', [
            'enabled' => true, 'interval_minutes' => 45,
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $app->id, 'worker_key' => 'discovery_quality', 'interval_minutes' => 45,
        ]);

        $this->withToken($token)->getJson($otherUrl)->assertOk()
            ->assertJsonPath('data.workers.0.enabled', false);
        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $other->id, 'worker_key' => 'discovery_quality',
        ]);

        $this->withToken($token)->putJson($url.'/workers/unknown', [
            'enabled' => true, 'interval_minutes' => 10,
        ])->assertUnprocessable();
    }

    public function test_manual_dispatch_needs_both_global_flag_and_enabled_control(): void
    {
        Queue::fake();
        $this->applicationFixture('cutinapp');
        $token = auth('api')->login($this->root());
        $base = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';

        $this->withToken($token)->postJson($base.'/dispatch')->assertStatus(409);

        $this->withToken($token)->putJson($base, [
            'enabled' => true, 'interval_minutes' => 30,
        ])->assertOk();
        config()->set('automation_workers.enabled', true);

        $this->withToken($token)->postJson($base.'/dispatch')->assertAccepted();
        $this->withToken($token)->postJson($base.'/dispatch')->assertStatus(409);
        Queue::assertPushed(RunAutomationWorker::class, 1);
    }
}
