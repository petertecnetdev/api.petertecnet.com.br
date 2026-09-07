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
                'payment_method' => 'pix',
                'total' => 120.50,
                'platform_fee' => 40.00,
                'created_at' => $now->subMinutes(8),
                'recovery_started_at' => null,
            ],
            (object) [
                'payment_method' => 'card',
                'total' => 300,
                'platform_fee' => 30,
                'created_at' => $now->subMinutes(25),
                'recovery_started_at' => null,
            ],
            (object) [
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
    }
}
