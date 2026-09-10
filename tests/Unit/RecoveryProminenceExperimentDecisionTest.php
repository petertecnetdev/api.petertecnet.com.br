<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RecoveryProminenceExperimentEconomics;
use Tests\TestCase;

final class RecoveryProminenceExperimentDecisionTest extends TestCase
{
    public function test_immature_sample_is_inconclusive(): void
    {
        $result = (new RecoveryProminenceExperimentEconomics())->summarize(
            [$this->event('order-1', 'control')],
            [$this->order('order-1', 'paid', 10.0, 2.0)],
            'pix_recovery_navbar_prominence_v1'
        );

        self::assertSame('inconclusive', $result['comparison']['decision']['status']);
        self::assertSame('sample_immature', $result['comparison']['decision']['reason']);
        self::assertFalse($result['comparison']['decision']['eligible_for_rollout']);
    }

    public function test_mature_treatment_wins_only_when_conversion_is_preserved_with_confidence_and_net_contribution_improves(): void
    {
        [$orders, $events] = $this->fixture(20, 60, 10.0, 10.0, 100);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('winner', $result['comparison']['decision']['status']);
        self::assertSame('prominent', $result['comparison']['decision']['recommended_variant']);
        self::assertSame('conversion_preserved_with_95_confidence_and_contribution_improved', $result['comparison']['decision']['reason']);
        self::assertTrue($result['comparison']['decision']['eligible_for_rollout']);
        self::assertTrue($result['comparison']['decision']['requires_manual_review']);
        self::assertTrue($result['comparison']['decision']['confidence']['paid_conversion_guardrail_satisfied']);
        self::assertGreaterThanOrEqual(0, $result['comparison']['paid_conversion_difference_confidence_95']['lower_paid_orders_per_100_exposed_orders']);
    }

    public function test_small_observed_conversion_gain_stays_inconclusive_when_confidence_interval_crosses_zero(): void
    {
        [$orders, $events] = $this->fixture(3, 6, 10.0, 10.0);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('inconclusive', $result['comparison']['decision']['status']);
        self::assertSame('paid_conversion_uncertainty', $result['comparison']['decision']['reason']);
        self::assertFalse($result['comparison']['decision']['eligible_for_rollout']);
        self::assertFalse($result['comparison']['decision']['confidence']['paid_conversion_guardrail_satisfied']);
        self::assertLessThan(0, $result['comparison']['paid_conversion_difference_confidence_95']['lower_paid_orders_per_100_exposed_orders']);
        self::assertGreaterThan(0, $result['comparison']['paid_conversion_difference_confidence_95']['upper_paid_orders_per_100_exposed_orders']);
    }

    public function test_conversion_drop_is_harmful_only_when_confidence_supports_harm(): void
    {
        [$orders, $events] = $this->fixture(60, 20, 10.0, 20.0, 100);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('harmful', $result['comparison']['decision']['status']);
        self::assertSame('paid_conversion_guardrail_failed_with_95_confidence', $result['comparison']['decision']['reason']);
        self::assertSame('control', $result['comparison']['decision']['recommended_variant']);
        self::assertTrue($result['comparison']['paid_conversion_difference_confidence_95']['supports_conversion_harm']);
    }

    public function test_net_contribution_drop_is_harmful_when_conversion_is_flat(): void
    {
        [$orders, $events] = $this->fixture(15, 15, 10.0, 8.0);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('harmful', $result['comparison']['decision']['status']);
        self::assertSame('platform_contribution_guardrail_failed', $result['comparison']['decision']['reason']);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function fixture(
        int $controlPaid,
        int $prominentPaid,
        float $controlFee,
        float $prominentFee,
        int $exposedOrdersPerVariant = 30,
    ): array {
        $orders = [];
        $events = [];

        for ($i = 1; $i <= $exposedOrdersPerVariant; $i++) {
            $controlId = 'control-'.$i;
            $prominentId = 'prominent-'.$i;
            $orders[] = $this->order($controlId, $i <= $controlPaid ? 'paid' : 'pending', $controlFee, 2.0);
            $orders[] = $this->order($prominentId, $i <= $prominentPaid ? 'paid' : 'pending', $prominentFee, 2.0);
            $events[] = $this->event($controlId, 'control');
            $events[] = $this->event($prominentId, 'prominent');
        }

        return [$orders, $events];
    }

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

    private function event(string $publicId, string $variant): array
    {
        return [
            'interaction_type' => 'frontend_checkout_recovery_notification_cta_viewed',
            'content' => ['metadata' => [
                'order_public_id' => $publicId,
                'recovery_prominence_experiment' => 'pix_recovery_navbar_prominence_v1',
                'recovery_prominence_variant' => $variant,
            ]],
        ];
    }
}
