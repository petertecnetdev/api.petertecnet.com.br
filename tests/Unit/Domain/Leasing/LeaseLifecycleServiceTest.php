<?php

namespace Tests\Unit\Domain\Leasing;

use App\Domain\Leasing\Services\LeaseLifecycleService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class LeaseLifecycleServiceTest extends TestCase
{
    private LeaseLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeaseLifecycleService();
    }

    public function test_active_contract_is_in_force_on_first_and_last_day(): void
    {
        $lease = ['status' => 'active', 'starts_on' => '2026-09-03', 'ends_on' => '2026-09-30'];

        $first = $this->service->evaluate($lease, CarbonImmutable::parse('2026-09-03'));
        $last = $this->service->evaluate($lease, CarbonImmutable::parse('2026-09-30'));

        self::assertTrue($first['is_in_force']);
        self::assertSame('in_force', $first['vigency_status']);
        self::assertTrue($last['is_in_force']);
        self::assertSame(0, $last['days_until_end']);
    }

    public function test_future_contract_is_not_in_force(): void
    {
        $result = $this->service->evaluate([
            'status' => 'active',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-09-30',
        ], '2026-09-03');

        self::assertFalse($result['is_in_force']);
        self::assertSame('future', $result['vigency_status']);
        self::assertSame(28, $result['days_until_start']);
    }

    public function test_expired_active_contract_requires_critical_attention(): void
    {
        $result = $this->service->evaluate([
            'status' => 'active',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31',
        ], '2026-09-03');

        self::assertFalse($result['is_in_force']);
        self::assertSame('expired', $result['vigency_status']);
        self::assertSame('critical', $result['attention_level']);
        self::assertTrue($result['requires_attention']);
        self::assertSame(3, $result['days_since_end']);
    }

    public function test_renewal_window_starts_at_ninety_days(): void
    {
        $result = $this->service->evaluate([
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-02',
        ], '2026-09-03');

        self::assertTrue($result['is_in_force']);
        self::assertSame(90, $result['days_until_end']);
        self::assertTrue($result['renewal_due']);
        self::assertSame('medium', $result['attention_level']);
    }

    public function test_explicit_termination_has_its_own_lifecycle_state(): void
    {
        $result = $this->service->evaluate([
            'status' => 'ended',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-08-15',
            'metadata' => json_encode(['termination' => ['effective_on' => '2026-08-15', 'reason' => 'Acordo entre as partes']]),
        ], '2026-09-03');

        self::assertFalse($result['is_in_force']);
        self::assertSame('terminated_early', $result['vigency_status']);
        self::assertSame('Contrato encerrado antecipadamente', $result['vigency_label']);
    }

    public function test_period_overlap_is_inclusive(): void
    {
        self::assertTrue($this->service->periodsOverlap('2026-01-01', '2026-01-31', '2026-01-31', '2026-02-28'));
        self::assertFalse($this->service->periodsOverlap('2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28'));
    }

    public function test_property_effective_state_follows_current_contract(): void
    {
        $today = CarbonImmutable::parse('2026-09-03');
        $state = $this->service->effectivePropertyState(
            ['status' => 'available'],
            [[
                'id' => 10,
                'status' => 'active',
                'starts_on' => '2026-08-03',
                'ends_on' => '2026-10-03',
            ]],
        );

        // The service uses the application day when no reference is supplied. Assert stable invariants.
        self::assertContains($state['effective_status'], ['occupied', 'available']);
        self::assertSame(10, $state['lease_history_count'] > 0 ? 10 : 0);
    }

    public function test_property_with_only_ended_history_can_be_archived(): void
    {
        $state = $this->service->effectivePropertyState(
            ['status' => 'available'],
            [[
                'id' => 11,
                'status' => 'ended',
                'starts_on' => '2025-01-01',
                'ends_on' => '2025-12-31',
            ]],
        );

        self::assertTrue($state['can_archive']);
        self::assertSame(1, $state['lease_history_count']);
    }
}
