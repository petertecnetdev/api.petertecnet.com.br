<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionEntitlementTest extends TestCase
{
    #[Test]
    public function it_resolves_nested_entitlements_from_the_plan(): void
    {
        $plan = new Plan([
            'entitlements' => [
                'discover' => ['unlimited' => true],
                'boosts' => ['monthly' => 3],
            ],
        ]);

        $subscription = new Subscription();
        $subscription->setRelation('plan', $plan);

        $this->assertTrue($subscription->hasEntitlement('discover.unlimited'));
        $this->assertSame(3, $subscription->entitlement('boosts.monthly'));
        $this->assertFalse($subscription->hasEntitlement('chat.read_receipts'));
    }

    #[Test]
    public function it_returns_defaults_when_the_subscription_has_no_plan(): void
    {
        $subscription = new Subscription();
        $subscription->setRelation('plan', null);

        $this->assertSame('free', $subscription->entitlement('tier', 'free'));
        $this->assertFalse($subscription->hasEntitlement('premium'));
    }
}
