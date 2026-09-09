<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RecoveryProminenceExperimentEconomics;
use Tests\TestCase;

final class RecoveryProminenceExperimentEconomicsTest extends TestCase
{
    public function test_it_keeps_incremental_metrics_null_until_both_variants_are_mature(): void
    {
        $orders = [
            $this->order('control-order', 'paid', 10.0, 2.0),
            $this->order('prominent-order', 'paid', 12.0, 2.0),
        ];

        $interactions = [
            $this->event('checkout_recovery_notification_cta_viewed', 'control-order', 'control'),
            $this->event('checkout_recovery_notification_cta_clicked', 'control-order', 'control'),
            $this->event('checkout_recovery_notification_cta_viewed', 'prominent-order', 'prominent'),
            $this->event('checkout_recovery_notification_cta_clicked', 'prominent-order', 'prominent'),
        ];

        $result = (new RecoveryProminenceExperimentEconomics())->summarize(
            $interactions,
            $orders,
            'pix_recovery_navbar_prominence_v1',
        );

        self::assertFalse($result['comparison']['sample_is_mature']);
        self::assertNull($result['comparison']['incremental_paid_orders_per_100_exposed_orders']);
        self::assertNull($result['comparison']['incremental_platform_contribution_per_exposed_order']);
        self::assertNull($result['comparison']['relative_contribution_lift_percent']);
    }

    public function test_it_calculates_incremental_margin_after_both_variants_reach_maturity(): void
    {
        $orders = [];
        $interactions = [];

        for ($i = 1; $i <= 30; $i++) {
            $controlId = 'control-'.$i;
            $prominentId = 'prominent-'.$i;

            $orders[] = $this->order($controlId, $i <= 3 ? 'paid' : 'pending', 10.0, 2.0);
            $orders[] = $this->order($prominentId, $i <= 6 ? 'paid' : 'pending', 10.0, 2.0);

            $interactions[] = $this->event('checkout_recovery_notification_cta_viewed', $controlId, 'control');
            $interactions[] = $this->event('checkout_recovery_notification_cta_viewed', $prominentId, 'prominent');

            // Keep click behavior diagnostic: paid outcomes do not depend on clicking.
            if ($i <= 2) {
                $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', $controlId, 'control');
            }
            if ($i <= 4) {
                $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', $prominentId, 'prominent');
            }
        }

        $result = (new RecoveryProminenceExperimentEconomics())->summarize(
            $interactions,
            $orders,
            'pix_recovery_navbar_prominence_v1',
        );

        self::assertSame('exposed_order', $result['unit_of_analysis']);
        self::assertTrue($result['comparison']['sample_is_mature']);
        self::assertSame(10.0, $result['comparison']['incremental_paid_orders_per_100_exposed_orders']);
        self::assertSame(0.8, $result['comparison']['incremental_platform_contribution_per_exposed_order']);
        self::assertSame(100.0, $result['comparison']['relative_contribution_lift_percent']);
    }

    public function test_paid_outcome_requires_exposure_but_not_a_click(): void
    {
        $result = (new RecoveryProminenceExperimentEconomics())->summarize([
            $this->event('checkout_recovery_notification_cta_viewed', 'paid-after-view', 'prominent'),
            $this->event('checkout_recovery_notification_cta_clicked', 'clicked-without-view', 'prominent'),
        ], [
            $this->order('paid-after-view', 'paid', 10.0, 2.0),
            $this->order('clicked-without-view', 'paid', 10.0, 2.0),
        ], 'pix_recovery_navbar_prominence_v1');

        $prominent = collect($result['variants'])->firstWhere('variant', 'prominent');

        self::assertSame(1, $prominent['exposed_orders']);
        self::assertSame(1, $prominent['paid_orders']);
        self::assertSame(8.0, $prominent['platform_contribution']);
    }

    public function test_it_ignores_other_experiments_and_orders_outside_the_scoped_set(): void
    {
        $result = (new RecoveryProminenceExperimentEconomics())->summarize([
            $this->event('checkout_recovery_notification_cta_viewed', 'scoped-order', 'control', 'other-experiment'),
            $this->event('checkout_recovery_notification_cta_viewed', 'outside-order', 'prominent'),
        ], [
            $this->order('scoped-order', 'pending', 10.0, 2.0),
        ], 'pix_recovery_navbar_prominence_v1');

        self::assertSame([], $result['variants']);
        self::assertFalse($result['comparison']['sample_is_mature']);
    }

    /** @return array<string, mixed> */
    private function order(string $publicId, string $status, float $platformFee, float $processorFee): array
    {
        return [
            'public_id' => $publicId,
            'status' => $status,
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'metadata' => ['settlement_mode' => 'platform_collection'],
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $type, string $publicId, string $variant, string $experiment = 'pix_recovery_navbar_prominence_v1'): array
    {
        return [
            'interaction_type' => 'frontend_'.$type,
            'content' => [
                'metadata' => [
                    'order_public_id' => $publicId,
                    'recovery_prominence_experiment' => $experiment,
                    'recovery_prominence_variant' => $variant,
                ],
            ],
        ];
    }
}
