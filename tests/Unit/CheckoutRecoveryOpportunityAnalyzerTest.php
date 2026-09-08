<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\CheckoutRecoveryOpportunityAnalyzer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class CheckoutRecoveryOpportunityAnalyzerTest extends TestCase
{
    public function test_it_prioritizes_recoverable_platform_revenue_over_gmv(): void
    {
        $now = CarbonImmutable::parse('2026-09-07 18:00:00');
        $orders = collect([
            (object) [
                'id' => 101,
                'payment_method' => 'pix',
                'total' => 120.50,
                'platform_fee' => 40.00,
                'created_at' => $now->subMinutes(8),
                'recovery_started_at' => null,
            ],
            (object) [
                'id' => 102,
                'payment_method' => 'card',
                'total' => 300,
                'platform_fee' => 30,
                'created_at' => $now->subMinutes(25),
                'recovery_started_at' => null,
            ],
            (object) [
                'id' => 103,
                'payment_method' => 'pix',
                'total' => 80,
                'platform_fee' => 8,
                'created_at' => $now->subHours(2),
                'recovery_started_at' => $now->subMinutes(30),
            ],
        ]);

        $result = (new CheckoutRecoveryOpportunityAnalyzer())->summarize($orders, $now);

        $this->assertSame(3, $result['orders']);
        $this->assertSame(2, $result['unattempted_orders']);
        $this->assertSame(420.50, $result['unattempted_gross_revenue']);
        $this->assertSame(70.0, $result['unattempted_platform_revenue']);

        $this->assertSame('pix', $result['by_payment_method'][0]['payment_method']);
        $this->assertSame(40.0, $result['by_payment_method'][0]['unattempted_platform_revenue']);
        $this->assertSame('card', $result['by_payment_method'][1]['payment_method']);
        $this->assertSame(300.0, $result['by_payment_method'][1]['unattempted_gross_revenue']);

        $this->assertSame('0_15m', $result['by_age_bucket'][0]['age_bucket']);
        $this->assertSame('15_60m', $result['by_age_bucket'][1]['age_bucket']);
        $this->assertSame('1_6h', $result['by_age_bucket'][2]['age_bucket']);
        $this->assertSame(1, $result['by_age_bucket'][2]['recovery_started_orders']);

        $this->assertCount(2, $result['priority_queue']);
        $this->assertSame('pix', $result['priority_queue'][0]['payment_method']);
        $this->assertSame('0_15m', $result['priority_queue'][0]['age_bucket']);
        $this->assertSame(40.0, $result['priority_queue'][0]['unattempted_platform_revenue']);
        $this->assertSame('recover_unattempted_checkout', $result['priority_queue'][0]['recommended_action']);
        $this->assertSame('card', $result['priority_queue'][1]['payment_method']);

        $this->assertCount(2, $result['top_opportunities']);
        $this->assertSame(101, $result['top_opportunities'][0]['order_id']);
        $this->assertSame(1, $result['top_opportunities'][0]['priority_rank']);
        $this->assertSame(40.0, $result['top_opportunities'][0]['platform_revenue']);
        $this->assertSame(102, $result['top_opportunities'][1]['order_id']);
        $this->assertSame(2, $result['top_opportunities'][1]['priority_rank']);
        $this->assertSame('recover_unattempted_checkout', $result['top_opportunities'][1]['recommended_action']);
    }

    public function test_it_prioritizes_expected_platform_revenue_using_recovery_history(): void
    {
        $now = CarbonImmutable::parse('2026-09-07 18:00:00');
        $orders = collect([
            (object) [
                'id' => 201,
                'payment_method' => 'pix',
                'total' => 200,
                'platform_fee' => 40,
                'created_at' => $now->subMinutes(10),
                'recovery_started_at' => null,
            ],
            (object) [
                'id' => 202,
                'payment_method' => 'card',
                'total' => 180,
                'platform_fee' => 30,
                'created_at' => $now->subMinutes(20),
                'recovery_started_at' => null,
            ],
            (object) [
                'id' => 203,
                'payment_method' => 'boleto',
                'total' => 100,
                'platform_fee' => 20,
                'created_at' => $now->subMinutes(30),
                'recovery_started_at' => null,
            ],
        ]);

        $result = (new CheckoutRecoveryOpportunityAnalyzer())->summarize(
            $orders,
            $now,
            recoveryProbabilityByPaymentMethod: ['pix' => 0.20, 'card' => 0.80],
            fallbackRecoveryProbability: 0.50
        );

        $this->assertSame(202, $result['top_opportunities'][0]['order_id']);
        $this->assertSame(24.0, $result['top_opportunities'][0]['expected_platform_revenue']);
        $this->assertSame(0.8, $result['top_opportunities'][0]['recovery_probability']);
        $this->assertSame('payment_method_history', $result['top_opportunities'][0]['recovery_probability_source']);
        $this->assertSame(203, $result['top_opportunities'][1]['order_id']);
        $this->assertSame(10.0, $result['top_opportunities'][1]['expected_platform_revenue']);
        $this->assertSame('overall_history', $result['top_opportunities'][1]['recovery_probability_source']);
        $this->assertSame(201, $result['top_opportunities'][2]['order_id']);
        $this->assertSame(8.0, $result['top_opportunities'][2]['expected_platform_revenue']);
        $this->assertSame('card', $result['priority_queue'][0]['payment_method']);
        $this->assertSame(24.0, $result['priority_queue'][0]['expected_platform_revenue']);
    }
}
