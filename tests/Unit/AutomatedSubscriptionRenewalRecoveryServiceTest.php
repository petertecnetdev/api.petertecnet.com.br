<?php

namespace Tests\Unit;

use App\Domain\Finance\Services\AutomatedSubscriptionRenewalRecoveryService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class AutomatedSubscriptionRenewalRecoveryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_waits_for_the_grace_window_before_recovering_an_expired_subscription(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        self::assertFalse($this->isRecoverable('2026-09-13 09:30:00'));
        self::assertTrue($this->isRecoverable('2026-09-13 09:00:00'));
        self::assertTrue($this->isRecoverable('2026-09-12 10:00:00'));
    }

    public function test_it_rejects_periods_outside_the_recovery_window_or_non_billable_subscriptions(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        self::assertFalse($this->isRecoverable('2026-08-13 09:59:59'));
        self::assertFalse($this->isRecoverable('2026-09-13 08:00:00', priceCents: 0));
        self::assertFalse($this->isRecoverable('2026-09-13 08:00:00', status: 'cancelled'));
        self::assertFalse($this->isRecoverable('2026-09-13 08:00:00', cancelledAt: '2026-09-13 08:30:00'));
    }

    private function isRecoverable(
        string $periodEnd,
        int $priceCents = 1000,
        string $status = 'active',
        ?string $cancelledAt = null,
    ): bool {
        $subscription = new stdClass();
        $subscription->status = $status;
        $subscription->cancelled_at = $cancelledAt;
        $subscription->price_cents = $priceCents;
        $subscription->current_period_end = $periodEnd;

        $now = CarbonImmutable::now();
        $oldestRecoverable = $now->subDays(30);
        $recoverableBefore = $now->subMinutes(60);

        $reflection = new ReflectionClass(AutomatedSubscriptionRenewalRecoveryService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isRecoverable');

        return (bool) $method->invoke($service, $subscription, $oldestRecoverable, $recoverableBefore);
    }
}
