<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\ObservedRecoveryChannelEconomics;
use PHPUnit\Framework\TestCase;

final class ObservedRecoveryChannelEconomicsTest extends TestCase
{
    public function test_it_ranks_channels_from_observed_cost_conversion_and_contribution(): void
    {
        $orders = [];
        for ($i = 0; $i < 100; $i++) {
            $orders[] = $this->order('email', 0.05, $i < 10, 'pix', 30, 10.0, 0.0);
            $orders[] = $this->order('whatsapp', 0.50, $i < 20, 'pix', 30, 10.0, 0.0);
        }

        $groups = (new ObservedRecoveryChannelEconomics())->summarize($orders);

        self::assertCount(1, $groups);
        self::assertSame('pix', $groups[0]['payment_method']);
        self::assertSame('15_60m', $groups[0]['abandonment_age_bucket']);
        self::assertSame('whatsapp', $groups[0]['recommended_channel']);
        self::assertSame('whatsapp', $groups[0]['channels'][0]['channel']);
        self::assertSame(100.0, $groups[0]['channels'][0]['cost_coverage_percent']);
        self::assertGreaterThan(
            $groups[0]['channels'][1]['expected_net_contribution_per_attempt'],
            $groups[0]['channels'][0]['expected_net_contribution_per_attempt'],
        );
    }

    public function test_it_does_not_recommend_a_channel_with_incomplete_cost_attribution(): void
    {
        $orders = [
            $this->order('whatsapp', 0.50, true, 'pix', 10, 10.0, 0.0),
            $this->order('whatsapp', null, true, 'pix', 10, 10.0, 0.0),
        ];

        $groups = (new ObservedRecoveryChannelEconomics())->summarize($orders);

        self::assertNull($groups[0]['recommended_channel']);
        self::assertSame(50.0, $groups[0]['channels'][0]['cost_coverage_percent']);
        self::assertNull($groups[0]['channels'][0]['attempt_cost']);
        self::assertFalse($groups[0]['channels'][0]['recommended']);
    }

    public function test_it_segments_by_payment_method_and_abandonment_age(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('email', 0.01, true, 'pix', 5, 5.0, 0.0),
            $this->order('email', 0.01, true, 'card', 90, 5.0, 0.0),
        ]);

        self::assertCount(2, $groups);
        self::assertSame(['card|1_6h', 'pix|0_15m'], array_map(
            static fn (array $group): string => $group['payment_method'].'|'.$group['abandonment_age_bucket'],
            $groups,
        ));
    }

    public function test_platform_collection_uses_processor_fee_in_real_contribution(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('email', 0.01, true, 'pix', 20, 5.0, 2.0, 'platform_collection'),
        ]);

        self::assertSame(3.0, $groups[0]['channels'][0]['recovered_platform_contribution']);
        self::assertSame(3.0, $groups[0]['channels'][0]['contribution_per_recovered_order']);
    }

    /** @return array<string, mixed> */
    private function order(
        string $channel,
        ?float $attemptCost,
        bool $paid,
        string $paymentMethod,
        int $ageMinutes,
        float $platformFee,
        float $processorFee,
        string $settlementMode = 'automatic_split',
    ): array {
        return [
            'payment_method' => $paymentMethod,
            'status' => $paid ? 'paid' : 'pending',
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'created_at' => '2026-09-08 10:00:00',
            'recovery_started_at' => date('Y-m-d H:i:s', strtotime('2026-09-08 10:00:00 +'.$ageMinutes.' minutes')),
            'metadata' => [
                'settlement_mode' => $settlementMode,
                'recovery' => [
                    'channel' => $channel,
                    'attempt_cost' => $attemptCost,
                ],
            ],
        ];
    }
}
