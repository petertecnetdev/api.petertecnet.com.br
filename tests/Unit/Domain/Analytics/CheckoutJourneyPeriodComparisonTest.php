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
    }

    /** @return array<string, mixed> */
    private function summary(int $opened, float $conversion, float $risk): array
    {
        return [
            'stages' => ['opened' => $opened],
            'conversion' => ['opened_to_approved_percent' => $conversion],
            'dropoff' => ['largest_contribution_step' => ['platform_contribution_at_risk' => $risk]],
        ];
    }
}
