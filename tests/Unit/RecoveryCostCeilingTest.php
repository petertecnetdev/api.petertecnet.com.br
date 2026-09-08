<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\ProfitabilityRiskPrioritizer;
use Tests\TestCase;

final class RecoveryCostCeilingTest extends TestCase
{
    public function test_it_exposes_confidence_adjusted_recovery_cost_ceiling_and_headroom(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([
            [
                'payment_method' => 'pix',
                'platform_revenue' => 100.0,
                'platform_contribution_after_processing' => 100.0,
                'platform_contribution_shortfall' => 0.0,
                'platform_loss_making_orders' => 0,
                'platform_loss_making_gross_revenue' => 0.0,
                'platform_collection_effective_fee_rate' => 3.0,
                'platform_collection_break_even_fee_rate' => 1.0,
                'platform_collection_fee_rate_gap_to_break_even' => 0.0,
                'platform_collection_sustainable' => true,
                'checkout_recovery_attempts' => 20,
                'checkout_recovered_orders' => 10,
                'recovered_platform_revenue' => 100.0,
                'gross_at_risk' => 500.0,
            ],
        ], ['pix' => 2.0]);

        self::assertCount(1, $result);
        self::assertSame('scale_carefully', $result[0]['recovery_decision']);
        self::assertSame(2.9929, $result[0]['recovery_confidence_adjusted_max_attempt_cost']);
        self::assertSame(0.9929, $result[0]['recovery_confidence_adjusted_cost_headroom']);
        self::assertSame(49.65, $result[0]['recovery_confidence_adjusted_cost_headroom_percent']);
    }

    public function test_it_exposes_safe_cost_ceiling_without_inventing_actual_channel_cost(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([
            [
                'payment_method' => 'pix',
                'platform_revenue' => 100.0,
                'platform_contribution_after_processing' => 100.0,
                'platform_contribution_shortfall' => 1.0,
                'platform_loss_making_orders' => 0,
                'platform_loss_making_gross_revenue' => 0.0,
                'platform_collection_effective_fee_rate' => 3.0,
                'platform_collection_break_even_fee_rate' => 1.0,
                'platform_collection_fee_rate_gap_to_break_even' => 0.0,
                'platform_collection_sustainable' => true,
                'checkout_recovery_attempts' => 20,
                'checkout_recovered_orders' => 10,
                'recovered_platform_revenue' => 100.0,
                'gross_at_risk' => 500.0,
            ],
        ]);

        self::assertCount(1, $result);
        self::assertNull($result[0]['recovery_attempt_cost']);
        self::assertSame(2.9929, $result[0]['recovery_confidence_adjusted_max_attempt_cost']);
        self::assertNull($result[0]['recovery_confidence_adjusted_cost_headroom']);
        self::assertNull($result[0]['recovery_confidence_adjusted_cost_headroom_percent']);
    }
}
