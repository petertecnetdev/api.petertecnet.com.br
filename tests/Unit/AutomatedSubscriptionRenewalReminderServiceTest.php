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

    public function test_it_uses_a_final_stage_inside_the_last_24_hours(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');

        self::assertSame('initial', $this->stageFor('2026-09-15 10:00:00'));
        self::assertSame('initial', $this->stageFor('2026-09-14 10:00:01'));
        self::assertSame('final', $this->stageFor('2026-09-14 10:00:00'));
        self::assertSame('final', $this->stageFor('2026-09-13 10:30:00'));
    }

    public function test_initial_stage_preserves_the_legacy_reference_and_final_stage_is_distinct(): void
    {
        $publicId = 'sub_123';
        $periodEnd = CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC');
        $legacyReference = hash('sha256', implode('|', [
            $publicId,
            $periodEnd->utc()->format('Y-m-d H:i:s'),
        ]));

        self::assertSame($legacyReference, $this->referenceFor($publicId, $periodEnd, 'initial'));
        self::assertNotSame($legacyReference, $this->referenceFor($publicId, $periodEnd, 'final'));
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

    private function stageFor(string $periodEnd): string
    {
        $reflection = new ReflectionClass(AutomatedSubscriptionRenewalReminderService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'reminderStage');

        return (string) $method->invoke(
            $service,
            CarbonImmutable::parse($periodEnd),
            CarbonImmutable::now(),
        );
    }

    private function referenceFor(string $publicId, CarbonImmutable $periodEnd, string $stage): string
    {
        $reflection = new ReflectionClass(AutomatedSubscriptionRenewalReminderService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'reminderReferenceId');

        return (string) $method->invoke($service, $publicId, $periodEnd, $stage);
    }
}
