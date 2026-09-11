<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\Services\CheckoutJourneyPeriodComparison;
use PHPUnit\Framework\TestCase;

final class CheckoutJourneyPeriodComparisonTest extends TestCase
{
    public function test_marks_improvement_when_conversion_rises_and_contribution_risk_falls(): void
    {
        $service = new CheckoutJourneyPeriodComparison();
        $result = $service->compare($this->summary(40, 62.5, 80), $this->summary(35, 50, 120), 7);

        $this->assertTrue($result['sample_is_comparable']);
        $this->assertSame('improving', $result['status']);
        $this->assertSame(12.5, $result['conversion']['opened_to_approved_delta_percentage_points']);
        $this->assertSame(-40.0, $result['platform_contribution_risk']['largest_step_amount_delta']);
    }

    public function test_does_not_claim_a_trend_with_small_samples(): void
    {
        $service = new CheckoutJourneyPeriodComparison();
        $result = $service->compare($this->summary(9, 66.7, 20), $this->summary(10, 50, 40), 7);

        $this->assertFalse($result['sample_is_comparable']);
        $this->assertSame('collecting', $result['status']);
        $this->assertSame('collecting', $result['recommended_action_effectiveness']['status']);
    }

    public function test_compares_recommended_action_against_the_same_checkout_step(): void
    {
        $service = new CheckoutJourneyPeriodComparison();
        $current = $this->summary(60, 60, 80, 72, 46, 'payment_attempted', 'payment_approved', 'attempted_to_approved_percent');
        $previous = $this->summary(55, 58, 200, 61, 70, 'checkout_opened', 'payment_attempted', 'opened_to_attempted_percent');

        $result = $service->compare($current, $previous, 7);
        $effectiveness = $result['recommended_action_effectiveness'];

        $this->assertSame('improving', $effectiveness['status']);
        $this->assertSame('improve_payment_approval', $effectiveness['action_code']);
        $this->assertSame('attempted_to_approved_percent', $effectiveness['target_metric']);
        $this->assertSame(72.0, $effectiveness['target_metric_current_percent']);
        $this->assertSame(61.0, $effectiveness['target_metric_previous_percent']);
        $this->assertSame(11.0, $effectiveness['target_metric_delta_percentage_points']);
        $this->assertSame(46.0, $effectiveness['platform_contribution_at_risk_current']);
        $this->assertSame(70.0, $effectiveness['platform_contribution_at_risk_previous_same_step']);
        $this->assertSame(-24.0, $effectiveness['platform_contribution_at_risk_delta']);
    }

    /** @return array<string, mixed> */
    private function summary(
        int $opened,
        float $conversion,
        float $largestRisk,
        float $targetRate = 70,
        float $targetRisk = 50,
        string $largestFrom = 'payment_attempted',
        string $largestTo = 'payment_approved',
        string $targetMetric = 'attempted_to_approved_percent',
    ): array {
        $actionCode = match ($largestFrom) {
            'checkout_opened' => 'reduce_payment_entry_friction',
            'payment_attempted' => 'improve_payment_approval',
            default => 'protect_post_payment_fulfillment',
        };

        return [
            'stages' => ['opened' => $opened],
            'conversion' => [
                'opened_to_approved_percent' => $conversion,
                'opened_to_attempted_percent' => $largestFrom === 'checkout_opened' ? $targetRate : 80,
                'attempted_to_approved_percent' => $largestFrom === 'payment_attempted' ? $targetRate : 65,
                'approved_to_fulfilled_percent' => $largestFrom === 'payment_approved' ? $targetRate : 95,
            ],
            'dropoff' => [
                'largest_contribution_step' => [
                    'from' => $largestFrom,
                    'to' => $largestTo,
                    'platform_contribution_at_risk' => $largestRisk,
                    'recommended_action' => [
                        'code' => $actionCode,
                        'target_metric' => $targetMetric,
                    ],
                ],
                'steps' => [
                    [
                        'from' => 'checkout_opened',
                        'to' => 'payment_attempted',
                        'platform_contribution_at_risk' => $largestFrom === 'checkout_opened' ? $targetRisk : 20,
                    ],
                    [
                        'from' => 'payment_attempted',
                        'to' => 'payment_approved',
                        'platform_contribution_at_risk' => $largestFrom === 'payment_attempted' ? $targetRisk : 70,
                    ],
                    [
                        'from' => 'payment_approved',
                        'to' => 'checkout_fulfilled',
                        'platform_contribution_at_risk' => $largestFrom === 'payment_approved' ? $targetRisk : 10,
                    ],
                ],
            ],
        ];
    }
}
