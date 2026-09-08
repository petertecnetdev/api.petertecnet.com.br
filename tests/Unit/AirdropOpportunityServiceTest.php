<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\AirdropOpportunityService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

final class AirdropOpportunityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_plan_preserves_reserve_and_fee_budget(): void
    {
        $plan = app(AirdropOpportunityService::class)->plan(280, 'moderado');

        $this->assertSame(280.0, $plan['capital_usdt']);
        $this->assertSame(84.0, $plan['reserve_usdt']);
        $this->assertSame(196.0, $plan['deployable_usdt']);
        $this->assertGreaterThan(0, $plan['fee_budget_usdt']);
        $this->assertTrue($plan['guardrails']['explicit_confirmation_required']);
    }

    public function test_policy_blocks_artificial_activity(): void
    {
        $service = app(AirdropOpportunityService::class);

        foreach (['wash_trading', 'fake_volume', 'self_trade', 'leverage_churn'] as $action) {
            $policy = $service->actionPolicy($action);
            $this->assertFalse($policy['allowed']);
        }
    }

    public function test_legitimate_task_can_be_recorded_after_confirmation_layer(): void
    {
        $service = app(AirdropOpportunityService::class);
        $campaign = $service->completeTask(42, 'starter-onchain-route', 'official-bridge', '0xabc');
        $task = collect($campaign['tasks'])->firstWhere('id', 'official-bridge');

        $this->assertTrue($task['completed']);
        $this->assertSame('0xabc', $task['reference']);
        $this->assertGreaterThan(0, $campaign['progress_pct']);
    }

    public function test_fake_volume_task_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AirdropOpportunityService::class)->completeTask(42, 'protocol-engagement-route', 'no-fake-volume');
    }
}
