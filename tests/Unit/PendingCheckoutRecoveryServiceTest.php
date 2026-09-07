<?php

namespace Tests\Unit;

use App\Domain\Commerce\Services\PendingCheckoutRecoveryService;
use App\Models\CommerceOrder;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class PendingCheckoutRecoveryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_exposes_real_payment_expiration_and_remaining_recovery_window(): void
    {
        Carbon::setTestNow('2026-09-06 21:00:00 America/Sao_Paulo');

        $order = new CommerceOrder();
        $order->expires_at = now()->addMinutes(30);

        $state = (new PendingCheckoutRecoveryService())->recoveryState($order);

        $this->assertTrue($state['payment_recovery_eligible']);
        $this->assertSame(now()->addMinutes(30)->toIso8601String(), $state['payment_expires_at']);
        $this->assertSame(1800, $state['payment_recovery_seconds_remaining']);
    }

    public function test_it_exposes_non_recoverable_state_without_an_eligible_order(): void
    {
        $state = (new PendingCheckoutRecoveryService())->recoveryState(null);

        $this->assertFalse($state['payment_recovery_eligible']);
        $this->assertNull($state['payment_expires_at']);
        $this->assertSame(0, $state['payment_recovery_seconds_remaining']);
    }
}
