<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\PixInitializationRecoveryEconomics;
use Tests\TestCase;

final class PixInitializationRecoveryEconomicsTest extends TestCase
{
    public function test_it_attributes_paid_gmv_and_platform_economics_to_resumed_pix_orders(): void
    {
        $interactions = [
            $this->interaction('frontend_pix_initialization_recovery_started', 'order-a', 'checkout'),
            $this->interaction('frontend_pix_initialization_resumed', 'order-a', 'checkout'),
            $this->interaction('frontend_pix_initialization_recovery_started', 'order-b', 'session'),
            $this->interaction('frontend_pix_initialization_resume_failed', 'order-b', 'session', true),
            $this->interaction('frontend_pix_initialization_recovery_started', 'order-c', 'session'),
            $this->interaction('frontend_pix_initialization_resume_failed', 'order-c', 'session', false),
        ];
        $orders = [
            $this->order('order-a', 'paid', 120.0, 12.0, 2.0, 'platform_collection'),
            $this->order('order-b', 'pending', 80.0, 8.0, 1.0, 'platform_collection'),
            $this->order('order-c', 'cancelled', 50.0, 5.0, 0.0, 'producer_collection'),
        ];

        $summary = (new PixInitializationRecoveryEconomics())->summarize($interactions, $orders);

        self::assertSame(3, $summary['orders_observed']);
        self::assertSame(3, $summary['started_orders']);
        self::assertSame(1, $summary['resumed_orders']);
        self::assertSame(1, $summary['retryable_failed_orders']);
        self::assertSame(1, $summary['terminal_failed_orders']);
        self::assertSame(1, $summary['paid_after_resume_orders']);
        self::assertSame(100.0, $summary['resume_to_paid_percent']);
        self::assertSame(120.0, $summary['recovered_gmv']);
        self::assertSame(12.0, $summary['recovered_platform_revenue']);
        self::assertSame(10.0, $summary['recovered_platform_contribution']);
        self::assertSame(10.0, $summary['observed_platform_take_rate_percent']);
        self::assertSame('checkout', $summary['by_source'][0]['source']);
        self::assertSame(120.0, $summary['by_source'][0]['recovered_gmv']);
    }

    public function test_it_deduplicates_events_by_order_and_ignores_unscoped_orders(): void
    {
        $interactions = [
            $this->interaction('frontend_pix_initialization_recovery_started', 'order-a', 'checkout'),
            $this->interaction('frontend_pix_initialization_recovery_started', 'order-a', 'checkout'),
            $this->interaction('frontend_pix_initialization_resumed', 'order-a', 'session'),
            $this->interaction('frontend_pix_initialization_resumed', 'order-a', 'session'),
            $this->interaction('frontend_pix_initialization_resumed', 'other-order', 'session'),
        ];

        $summary = (new PixInitializationRecoveryEconomics())->summarize(
            $interactions,
            [$this->order('order-a', 'paid', 30.0, 3.0, 0.0, 'producer_collection')],
        );

        self::assertSame(1, $summary['orders_observed']);
        self::assertSame(1, $summary['started_orders']);
        self::assertSame(1, $summary['resumed_orders']);
        self::assertSame(1, $summary['paid_after_resume_orders']);
        self::assertSame(30.0, $summary['recovered_gmv']);
        self::assertSame('session', $summary['by_source'][0]['source']);
    }

    /** @return array<string, mixed> */
    private function interaction(string $type, string $publicId, string $source, ?bool $retryable = null): array
    {
        $metadata = ['order_public_id' => $publicId, 'source' => $source];
        if ($retryable !== null) {
            $metadata['retryable'] = $retryable;
        }

        return ['interaction_type' => $type, 'content' => ['metadata' => $metadata]];
    }

    /** @return array<string, mixed> */
    private function order(string $publicId, string $status, float $total, float $platformFee, float $processorFee, string $settlementMode): array
    {
        return [
            'public_id' => $publicId,
            'status' => $status,
            'total' => $total,
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'metadata' => ['settlement_mode' => $settlementMode],
        ];
    }
}
