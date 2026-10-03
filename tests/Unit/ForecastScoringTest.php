<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ForecastScoringTest extends TestCase
{
    public function test_brier_score_penalizes_overconfident_wrong_forecast(): void
    {
        $right=(0.9-1.0)**2;
        $wrong=(0.9-0.0)**2;
        $this->assertLessThan($wrong,$right);
        $this->assertEqualsWithDelta(0.01,$right,0.000001);
        $this->assertEqualsWithDelta(0.81,$wrong,0.000001);
    }

    public function test_small_samples_are_shrunk_toward_neutral_reputation(): void
    {
        $raw=95.0;$n=2;$confidence=100*(1-exp(-$n/25));$score=50+(($raw-50)*($confidence/100));
        $this->assertGreaterThan(50,$score);
        $this->assertLessThan(70,$score);
    }
}
