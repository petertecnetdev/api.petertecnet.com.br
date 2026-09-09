<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RecoveryChannelProfitabilitySelector;
use PHPUnit\Framework\TestCase;

final class RecoveryChannelDerivedSafeCeilingTest extends TestCase
{
    public function test_it_can_derive_a_safe_ceiling_from_mature_observed_economics(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.50,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
                'derive_safe_attempt_cost_ceiling' => true,
            ],
        ]);

        self::assertTrue($ranked[0]['has_minimum_sample']);
        self::assertSame('observed_conservative', $ranked[0]['safe_attempt_cost_ceiling_source']);
        self::assertSame(RecoveryChannelProfitabilitySelector::DEFAULT_MAX_SAFE_COST_SHARE, $ranked[0]['max_safe_cost_share']);
        self::assertNotNull($ranked[0]['derived_safe_attempt_cost_ceiling']);
        self::assertGreaterThan(0.50, $ranked[0]['safe_attempt_cost_ceiling']);
        self::assertTrue($ranked[0]['within_safe_cost_ceiling']);
        self::assertTrue($ranked[0]['recommended']);
    }

    public function test_it_does_not_derive_spend_permission_before_the_minimum_sample(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.10,
                'conversion_rate' => 50.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 10,
                'derive_safe_attempt_cost_ceiling' => true,
            ],
        ]);

        self::assertNull($ranked[0]['derived_safe_attempt_cost_ceiling']);
        self::assertNull($ranked[0]['safe_attempt_cost_ceiling']);
        self::assertContains('insufficient_sample', $ranked[0]['recommendation_blockers']);
        self::assertContains('missing_safe_cost_ceiling', $ranked[0]['recommendation_blockers']);
        self::assertFalse($ranked[0]['recommended']);
    }

    public function test_explicit_ceiling_remains_the_stricter_cap_when_observed_ceiling_is_higher(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.50,
                'max_safe_attempt_cost' => 0.20,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
                'derive_safe_attempt_cost_ceiling' => true,
            ],
        ]);

        self::assertSame('configured_and_observed_min', $ranked[0]['safe_attempt_cost_ceiling_source']);
        self::assertSame(0.20, $ranked[0]['safe_attempt_cost_ceiling']);
        self::assertFalse($ranked[0]['within_safe_cost_ceiling']);
        self::assertContains('above_safe_cost_ceiling', $ranked[0]['recommendation_blockers']);
        self::assertFalse($ranked[0]['recommended']);
    }

    public function test_derived_ceiling_reserves_conservative_contribution_as_margin(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 3.00,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
                'derive_safe_attempt_cost_ceiling' => true,
            ],
        ]);

        self::assertNotNull($ranked[0]['derived_safe_attempt_cost_ceiling']);
        self::assertLessThan($ranked[0]['expected_contribution_per_attempt'], $ranked[0]['safe_attempt_cost_ceiling']);
        self::assertFalse($ranked[0]['within_safe_cost_ceiling']);
        self::assertFalse($ranked[0]['recommended']);
    }
}
