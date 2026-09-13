<?php

namespace Tests\Unit;

use App\Domain\Finance\Services\SubscriptionPeriodCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SubscriptionPeriodCalculatorTest extends TestCase
{
    public function test_early_renewal_keeps_paid_days_and_extends_from_current_period_end(): void
    {
        $calculator = new SubscriptionPeriodCalculator();
        $now = Carbon::parse('2026-09-13 10:00:00');

        $period = $calculator->renewalPeriod(
            $now,
            '2026-08-20 10:00:00',
            '2026-09-20 10:00:00',
            'month',
            1,
        );

        $this->assertSame('2026-08-20 10:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 10:00:00', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_expired_subscription_starts_new_period_from_payment_time(): void
    {
        $calculator = new SubscriptionPeriodCalculator();
        $now = Carbon::parse('2026-09-13 10:00:00');

        $period = $calculator->renewalPeriod(
            $now,
            '2026-08-01 10:00:00',
            '2026-09-01 10:00:00',
            'month',
            1,
        );

        $this->assertSame('2026-09-13 10:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 10:00:00', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_monthly_renewal_does_not_overflow_short_months(): void
    {
        $calculator = new SubscriptionPeriodCalculator();
        $now = Carbon::parse('2026-01-31 10:00:00');

        $period = $calculator->renewalPeriod($now, null, null, 'month', 1);

        $this->assertSame('2026-02-28 10:00:00', $period['end']->format('Y-m-d H:i:s'));
    }
}
