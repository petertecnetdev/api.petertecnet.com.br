<?php

namespace Tests\Unit;

use App\Domain\Finance\Services\AutomatedSubscriptionRenewalReminderService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class AutomatedSubscriptionRenewalReminderServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_reminds_only_inside_the_72_hour_window(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        self::assertFalse($this->isEligible('2026-09-16 10:00:01'));
        self::assertTrue($this->isEligible('2026-09-16 10:00:00'));
        self::assertTrue($this->isEligible('2026-09-14 10:00:00'));
        self::assertFalse($this->isEligible('2026-09-13 10:00:00'));
    }

    public function test_it_rejects_non_billable_or_cancelled_subscriptions(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        self::assertFalse($this->isEligible('2026-09-14 10:00:00', priceCents: 0));
        self::assertFalse($this->isEligible('2026-09-14 10:00:00', status: 'cancelled'));
        self::assertFalse($this->isEligible('2026-09-14 10:00:00', cancelledAt: '2026-09-13 09:00:00'));
    }

    private function isEligible(
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
        $remindBefore = $now->addHours(72);

        $reflection = new ReflectionClass(AutomatedSubscriptionRenewalReminderService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isReminderEligible');

        return (bool) $method->invoke($service, $subscription, $now, $remindBefore);
    }
}
