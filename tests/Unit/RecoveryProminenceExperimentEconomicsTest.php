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

        $result = (new RecoveryProminenceExperimentEconomics())->summarize($interactions, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertFalse($result['comparison']['sample_is_mature']);
        self::assertNull($result['comparison']['incremental_paid_orders_per_100_exposed_orders']);
        self::assertNull($result['comparison']['incremental_platform_contribution_per_exposed_order']);
        self::assertNull($result['comparison']['relative_contribution_lift_percent']);
        self::assertNull($result['comparison']['observed_volume_projection']);
        self::assertNull($result['comparison']['treatment_observed_incremental_estimate']);
        self::assertSame('collecting', $result['comparison']['rollout_readiness']['status']);
        self::assertSame('collect_more_data', $result['comparison']['rollout_readiness']['recommended_action']);
        self::assertFalse($result['comparison']['rollout_readiness']['eligible_for_rollout']);
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
            if ($i <= 2) {
                $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', $controlId, 'control');
            }
            if ($i <= 4) {
                $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', $prominentId, 'prominent');
            }
        }

        $result = (new RecoveryProminenceExperimentEconomics())->summarize($interactions, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('exposed_order', $result['unit_of_analysis']);
        self::assertTrue($result['comparison']['sample_is_mature']);
        self::assertSame(10.0, $result['comparison']['incremental_paid_orders_per_100_exposed_orders']);
        self::assertSame(10.0, $result['comparison']['incremental_gmv_per_exposed_order']);
        self::assertSame(0.8, $result['comparison']['incremental_platform_contribution_per_exposed_order']);
        self::assertSame(100.0, $result['comparison']['relative_contribution_lift_percent']);
        self::assertSame([
            'basis' => 'observed_exposed_orders',
            'observed_exposed_orders' => 60,
            'projected_incremental_paid_orders' => 6.0,
            'projected_incremental_gmv' => 600.0,
            'projected_incremental_platform_contribution' => 48.0,
            'is_projection_not_realized_revenue' => true,
        ], $result['comparison']['observed_volume_projection']);
        self::assertSame([
            'basis' => 'treatment_exposed_orders',
            'attribution_method' => 'randomized_variant_difference',
            'treatment_exposed_orders' => 30,
            'estimated_incremental_paid_orders' => 3.0,
            'estimated_incremental_gmv' => 300.0,
            'estimated_incremental_platform_contribution' => 24.0,
            'is_causal_estimate_not_booked_revenue' => true,
        ], $result['comparison']['treatment_observed_incremental_estimate']);
        self::assertSame('hold', $result['comparison']['rollout_readiness']['status']);
        self::assertSame('keep_control', $result['comparison']['rollout_readiness']['recommended_action']);
        self::assertFalse($result['comparison']['rollout_readiness']['guardrails']['conversion']);
        self::assertTrue($result['comparison']['rollout_readiness']['guardrails']['contribution']);
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
        self::assertSame(100.0, $prominent['paid_gmv']);
        self::assertSame(8.0, $prominent['platform_contribution']);
    }

    public function test_first_exposed_variant_wins_when_telemetry_conflicts(): void
    {
        $result = (new RecoveryProminenceExperimentEconomics())->summarize([
            $this->event('checkout_recovery_notification_cta_viewed', 'order-1', 'control'),
            $this->event('checkout_recovery_notification_cta_viewed', 'order-1', 'prominent'),
            $this->event('checkout_recovery_notification_cta_clicked', 'order-1', 'prominent'),
            $this->event('checkout_recovery_notification_cta_clicked', 'order-1', 'control'),
        ], [$this->order('order-1', 'paid', 10.0, 2.0)], 'pix_recovery_navbar_prominence_v1');

        $control = collect($result['variants'])->firstWhere('variant', 'control');
        self::assertSame(1, $control['cta_impressions']);
        self::assertSame(1, $control['exposed_orders']);
        self::assertSame(1, $control['cta_clicks']);
        self::assertSame(1, $control['paid_orders']);
        self::assertNull(collect($result['variants'])->firstWhere('variant', 'prominent'));
    }

    public function test_it_ignores_other_experiments_and_orders_outside_the_scoped_set(): void
    {
        $result = (new RecoveryProminenceExperimentEconomics())->summarize([
            $this->event('checkout_recovery_notification_cta_viewed', 'scoped-order', 'control', 'other-experiment'),
            $this->event('checkout_recovery_notification_cta_viewed', 'outside-order', 'prominent'),
        ], [$this->order('scoped-order', 'pending', 10.0, 2.0)], 'pix_recovery_navbar_prominence_v1');

        self::assertSame([], $result['variants']);
        self::assertFalse($result['comparison']['sample_is_mature']);
    }

    private function order(string $publicId, string $status, float $platformFee, float $processorFee, float $total = 100.0): array
    {
        return [
            'public_id' => $publicId,
            'status' => $status,
            'total' => $total,
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'metadata' => ['settlement_mode' => 'platform_collection'],
        ];
    }

    private function event(string $type, string $publicId, string $variant, string $experiment = 'pix_recovery_navbar_prominence_v1'): array
    {
        return [
            'interaction_type' => 'frontend_'.$type,
            'content' => ['metadata' => [
                'order_public_id' => $publicId,
                'recovery_prominence_experiment' => $experiment,
                'recovery_prominence_variant' => $variant,
            ]],
        ];
    }
}
