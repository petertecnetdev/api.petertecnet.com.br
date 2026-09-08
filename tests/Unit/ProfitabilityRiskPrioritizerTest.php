<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\ProfitabilityRiskPrioritizer;
use PHPUnit\Framework\TestCase;

final class ProfitabilityRiskPrioritizerTest extends TestCase
{
    public function test_it_filters_sustainable_methods_and_prioritizes_realized_cash_shortfall(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([
            [
                'payment_method' => 'pix',
                'platform_contribution_shortfall' => 0.0,
                'platform_loss_making_orders' => 0,
                'platform_loss_making_gross_revenue' => 0.0,
                'platform_collection_effective_fee_rate' => 3.0,
                'platform_collection_break_even_fee_rate' => 1.2,
                'platform_collection_fee_rate_gap_to_break_even' => 0.0,
                'platform_collection_sustainable' => true,
                'gross_at_risk' => 100.0,
            ],
            [
                'payment_method' => 'credit_card',
                'platform_contribution_shortfall' => 82.35,
                'platform_loss_making_orders' => 4,
                'platform_loss_making_gross_revenue' => 1200.0,
                'platform_collection_effective_fee_rate' => 2.5,
                'platform_collection_break_even_fee_rate' => 4.1,
                'platform_collection_fee_rate_gap_to_break_even' => 1.6,
                'platform_collection_sustainable' => false,
                'gross_at_risk' => 430.0,
            ],
            [
                'payment_method' => 'debit_card',
                'platform_contribution_shortfall' => 12.0,
                'platform_loss_making_orders' => 1,
                'platform_loss_making_gross_revenue' => 250.0,
                'platform_collection_effective_fee_rate' => 2.0,
                'platform_collection_break_even_fee_rate' => 2.4,
                'platform_collection_fee_rate_gap_to_break_even' => 0.4,
                'platform_collection_sustainable' => false,
                'gross_at_risk' => 90.0,
            ],
        ]);

        self::assertCount(2, $result);
        self::assertSame('credit_card', $result[0]['payment_method']);
        self::assertSame(82.35, $result[0]['platform_contribution_shortfall']);
        self::assertSame(4, $result[0]['platform_loss_making_orders']);
        self::assertSame('debit_card', $result[1]['payment_method']);
    }

    public function test_it_surfaces_break_even_gap_before_realized_shortfall(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([[
            'payment_method' => 'credit_card',
            'platform_contribution_shortfall' => 0.0,
            'platform_loss_making_orders' => 0,
            'platform_loss_making_gross_revenue' => 0.0,
            'platform_collection_effective_fee_rate' => 3.0,
            'platform_collection_break_even_fee_rate' => 3.2,
            'platform_collection_fee_rate_gap_to_break_even' => 0.2,
            'platform_collection_sustainable' => false,
            'gross_at_risk' => 500.0,
        ]]);

        self::assertCount(1, $result);
        self::assertSame(0.0, $result[0]['platform_contribution_shortfall']);
        self::assertSame(0.2, $result[0]['platform_collection_fee_rate_gap_to_break_even']);
        self::assertSame(500.0, $result[0]['gross_at_risk']);
    }

    public function test_it_surfaces_negative_checkout_recovery_roi_when_real_attempt_cost_is_known(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([
            [
                'payment_method' => 'credit_card',
                'platform_revenue' => 100.0,
                'platform_contribution_after_processing' => 50.0,
                'platform_contribution_shortfall' => 0.0,
                'platform_loss_making_orders' => 0,
                'platform_loss_making_gross_revenue' => 0.0,
                'platform_collection_effective_fee_rate' => 5.0,
                'platform_collection_break_even_fee_rate' => 3.0,
                'platform_collection_fee_rate_gap_to_break_even' => 0.0,
                'platform_collection_sustainable' => true,
                'checkout_recovery_attempts' => 10,
                'checkout_recovered_orders' => 2,
                'recovered_platform_revenue' => 8.0,
                'gross_at_risk' => 200.0,
            ],
        ], [
            'credit_card' => 1.0,
        ]);

        self::assertCount(1, $result);
        self::assertSame('credit_card', $result[0]['payment_method']);
        self::assertSame(4.0, $result[0]['estimated_recovered_platform_contribution']);
        self::assertSame(10.0, $result[0]['recovery_total_attempt_cost']);
        self::assertSame(-6.0, $result[0]['recovery_net_platform_contribution']);
        self::assertSame(6.0, $result[0]['recovery_net_shortfall']);
        self::assertSame(-60.0, $result[0]['recovery_roi_percent']);
        self::assertFalse($result[0]['recovery_economically_sustainable']);
        self::assertSame('reduce_or_pause', $result[0]['recovery_decision']);
        self::assertSame(20.0, $result[0]['checkout_recovery_conversion_rate']);
        self::assertSame(50.0, $result[0]['checkout_recovery_break_even_conversion_rate']);
        self::assertSame(-30.0, $result[0]['checkout_recovery_conversion_safety_margin']);
        self::assertSame(0.4, $result[0]['recovery_contribution_per_attempt']);
        self::assertSame(2.0, $result[0]['recovery_contribution_per_recovered_order']);
        self::assertSame('payment_method_config', $result[0]['recovery_cost_source']);
    }

    public function test_it_recommends_scaling_only_after_minimum_sample_with_strong_positive_roi_and_safety_margin(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([[
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
            'checkout_recovery_attempts' => 12,
            'checkout_recovered_orders' => 6,
            'recovered_platform_revenue' => 30.0,
            'gross_at_risk' => 300.0,
        ]], ['pix' => 1.0]);

        self::assertCount(1, $result);
        self::assertSame('scale_carefully', $result[0]['recovery_decision']);
        self::assertSame(150.0, $result[0]['recovery_roi_percent']);
        self::assertSame(50.0, $result[0]['checkout_recovery_conversion_rate']);
        self::assertSame(20.0, $result[0]['checkout_recovery_break_even_conversion_rate']);
        self::assertSame(30.0, $result[0]['checkout_recovery_conversion_safety_margin']);
        self::assertSame(2.5, $result[0]['recovery_contribution_per_attempt']);
        self::assertSame(5.0, $result[0]['recovery_contribution_per_recovered_order']);
        self::assertSame(10, $result[0]['recovery_decision_minimum_attempts']);
        self::assertSame(10.0, $result[0]['recovery_scale_minimum_conversion_safety_margin']);
    }

    public function test_it_does_not_scale_when_roi_is_strong_but_conversion_has_thin_safety_margin(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([[
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
            'checkout_recovered_orders' => 4,
            'recovered_platform_revenue' => 24.0,
            'gross_at_risk' => 300.0,
        ]], ['pix' => 1.0]);

        self::assertCount(1, $result);
        self::assertSame(20.0, $result[0]['checkout_recovery_conversion_rate']);
        self::assertSame(16.67, $result[0]['checkout_recovery_break_even_conversion_rate']);
        self::assertSame(3.33, $result[0]['checkout_recovery_conversion_safety_margin']);
        self::assertSame(20.0, $result[0]['recovery_roi_percent']);
        self::assertSame('maintain_and_monitor', $result[0]['recovery_decision']);
    }

    public function test_it_does_not_scale_from_a_small_sample_even_with_positive_roi(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([[
            'payment_method' => 'pix',
            'platform_revenue' => 50.0,
            'platform_contribution_after_processing' => 50.0,
            'platform_contribution_shortfall' => 5.0,
            'platform_loss_making_orders' => 1,
            'platform_loss_making_gross_revenue' => 50.0,
            'platform_collection_effective_fee_rate' => 3.0,
            'platform_collection_break_even_fee_rate' => 1.0,
            'platform_collection_fee_rate_gap_to_break_even' => 0.0,
            'platform_collection_sustainable' => true,
            'checkout_recovery_attempts' => 3,
            'checkout_recovered_orders' => 2,
            'recovered_platform_revenue' => 12.0,
            'gross_at_risk' => 100.0,
        ]], ['pix' => 1.0]);

        self::assertCount(1, $result);
        self::assertSame('collect_more_data', $result[0]['recovery_decision']);
    }

    public function test_it_does_not_invent_recovery_roi_without_configured_cost(): void
    {
        $result = (new ProfitabilityRiskPrioritizer())->prioritize([[
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
            'checkout_recovered_orders' => 0,
            'recovered_platform_revenue' => 0.0,
            'gross_at_risk' => 300.0,
        ]], [], null);

        self::assertSame([], $result);
    }
}
