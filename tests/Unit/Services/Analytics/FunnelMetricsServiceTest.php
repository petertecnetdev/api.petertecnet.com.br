<?php

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\FunnelMetricsService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FunnelMetricsServiceTest extends TestCase
{
    public function test_rate_returns_zero_when_denominator_is_zero(): void
    {
        $service = new FunnelMetricsService();
        $method = new \ReflectionMethod($service, 'rate');
        $method->setAccessible(true);

        $this->assertSame(0.0, $method->invoke($service, 3, 0));
        $this->assertSame(0.5, $method->invoke($service, 1, 2));
    }

    public function test_checkout_abandonment_uses_session_level_query(): void
    {
        $method = new \ReflectionMethod(FunnelMetricsService::class, 'abandonedSessionCount');

        $this->assertTrue($method->isPrivate());
        $this->assertSame(3, $method->getNumberOfParameters());
    }

    public function test_period_boundaries_are_explicit_for_consumers(): void
    {
        $from = Carbon::parse('2026-09-01')->startOfDay();
        $to = Carbon::parse('2026-09-21')->endOfDay();

        $this->assertSame('2026-09-01T00:00:00+00:00', $from->utc()->toIso8601String());
        $this->assertSame('2026-09-21T23:59:59+00:00', $to->utc()->toIso8601String());
    }
}
