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

    public function test_mature_treatment_wins_only_when_conversion_is_preserved_and_net_contribution_improves(): void
    {
        [$orders, $events] = $this->fixture(3, 6, 10.0, 10.0);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('winner', $result['comparison']['decision']['status']);
        self::assertSame('prominent', $result['comparison']['decision']['recommended_variant']);
        self::assertTrue($result['comparison']['decision']['eligible_for_rollout']);
        self::assertTrue($result['comparison']['decision']['requires_manual_review']);
    }

    public function test_conversion_drop_is_harmful_even_when_treatment_fee_is_higher(): void
    {
        [$orders, $events] = $this->fixture(6, 3, 10.0, 20.0);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('harmful', $result['comparison']['decision']['status']);
        self::assertSame('paid_conversion_guardrail_failed', $result['comparison']['decision']['reason']);
        self::assertSame('control', $result['comparison']['decision']['recommended_variant']);
    }

    public function test_net_contribution_drop_is_harmful_when_conversion_is_flat(): void
    {
        [$orders, $events] = $this->fixture(3, 3, 10.0, 8.0);
        $result = (new RecoveryProminenceExperimentEconomics())->summarize($events, $orders, 'pix_recovery_navbar_prominence_v1');

        self::assertSame('harmful', $result['comparison']['decision']['status']);
        self::assertSame('platform_contribution_guardrail_failed', $result['comparison']['decision']['reason']);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function fixture(int $controlPaid, int $prominentPaid, float $controlFee, float $prominentFee): array
    {
        $orders = [];
        $events = [];

        for ($i = 1; $i <= 30; $i++) {
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
