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
}
